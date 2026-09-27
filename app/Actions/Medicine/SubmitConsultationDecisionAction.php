<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodePriority;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Consultation;
use App\Models\HospitalStay;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use App\Support\Medicine\ConsultationOrientationPrefill;
use Illuminate\Validation\ValidationException;

/**
 * ADR-203 — la conduite à tenir part au moment où le
 * médecin clôture.
 *
 * « Décision & clôture » demandait deux gestes pour une seule décision :
 * transmettre la demande, puis clôturer. Désormais le médecin choisit la
 * conduite, la complète s'il le veut, et « Clôturer » la transmet puis conclut
 * la rencontre, dans la même transaction.
 *
 * Rien n'est réécrit : chaque conduite part par l'action qui la porte déjà —
 * sortie médicale, hospitalisation, chirurgie, transfert, Maternité, Pédiatrie
 * — avec ses propres garde-fous. Ce que le médecin n'a pas saisi est repris du
 * dossier (`ConsultationOrientationPrefill`, §17), jamais inventé.
 *
 * Une conduite déjà transmise (une demande partie plus tôt, une sortie déjà
 * prononcée) n'est pas renvoyée : la clôture la retrouve telle qu'elle est.
 */
class SubmitConsultationDecisionAction
{
    public function __construct(
        private readonly ConsultationWorkflow $workflow,
        private readonly RecordConsultationOrientationAction $recordOrientation,
        private readonly RecordMedicalDischargeAction $recordDischarge,
        private readonly CreateHospitalizationRequestAction $requestHospitalization,
        private readonly CreateSurgicalReferralAction $requestSurgery,
        private readonly CreateMedicalReferralAction $requestReferral,
        private readonly CreateServiceReferralAction $requestService,
    ) {}

    /**
     * @param  array<string, mixed>  $decision  ce que l'écran envoie ; vide, la conduite déjà choisie part avec ses valeurs par défaut
     */
    public function execute(Consultation $consultation, array $decision, User $actor): void
    {
        $active = $this->workflow->activeOrientation($consultation->fresh());
        $chosen = ConsultationOrientationType::tryFrom((string) ($decision['type'] ?? ''));

        if ($active?->isSubmitted()) {
            // Une autre conduite ne remplace pas en silence une demande déjà
            // partie : la changer l'annule, et c'est un geste à part.
            if ($chosen !== null && $chosen !== $active->type) {
                throw ValidationException::withMessages([
                    'decision.type' => "« {$active->type->label()} » est déjà transmise. Changez de conduite d’abord : la demande actuelle sera annulée, jamais effacée.",
                ]);
            }

            return;
        }

        $type = $chosen ?? $active?->type;

        if ($type === null) {
            return;
        }

        $this->ensureAllowed($consultation, $type, $actor);

        $priority = ClinicalPriority::tryFrom((string) ($decision['priority'] ?? ''))
            ?? $active?->priority
            ?? ($consultation->episode->priority === EpisodePriority::Emergency ? ClinicalPriority::Urgent : ClinicalPriority::Normal);
        $prefill = ConsultationOrientationPrefill::compose($consultation);
        $notes = $this->text($decision['notes'] ?? null);

        match ($type) {
            ConsultationOrientationType::Discharge => $this->recordDischarge->execute(
                $consultation->orientation,
                $this->dischargeData($decision, $prefill),
                $actor,
            ),
            ConsultationOrientationType::Hospitalization => $this->requestHospitalization->execute($consultation, [
                'reason' => $prefill['reason'],
                'admission_diagnosis' => $prefill['diagnosis'],
                'clinical_summary' => $this->join($prefill['clinical_summary'], $prefill['paraclinical']),
                'planned_treatment' => $prefill['treatments'],
                'priority' => $priority->value,
                'instructions' => $notes,
            ], $actor),
            ConsultationOrientationType::Surgery => $this->requestSurgery->execute(
                $consultation,
                $this->surgeryItem($decision),
                null,
                $prefill['clinical_summary'],
                $priority->value,
                $notes,
                $actor,
            ),
            ConsultationOrientationType::Referral => $this->requestReferral->execute($consultation, [
                'facility' => $this->text($decision['facility'] ?? null),
                'reason' => $prefill['reason'],
                'diagnosis' => $prefill['diagnosis'],
                'clinical_summary' => $this->join($prefill['clinical_summary'], $prefill['paraclinical']),
                'treatments_given' => $prefill['treatments'],
                'priority' => $priority->value,
                'notes' => $notes,
            ], $actor),
            ConsultationOrientationType::Maternity,
            ConsultationOrientationType::Pediatrics => $this->requestService->execute(
                $consultation,
                $type === ConsultationOrientationType::Maternity ? CatalogModule::Maternity : CatalogModule::Pediatrics,
                $this->serviceReason($prefill, $notes),
                $actor,
                $priority,
            ),
            // Rien n'est demandé à personne : choisir suffit (ADR-149).
            ConsultationOrientationType::ContinuedHospitalization => $this->recordOrientation->select($consultation, $type, $priority, $actor),
        };
    }

    /** Le droit de la destination, et une conduite qui a un sens pour ce patient — revérifiés ici. */
    private function ensureAllowed(Consultation $consultation, ConsultationOrientationType $type, User $actor): void
    {
        if (! $actor->can($type->permission())) {
            throw ValidationException::withMessages([
                'decision.type' => "Votre compte n’est pas autorisé à orienter vers « {$type->label()} ».",
            ]);
        }

        $hospitalized = HospitalStay::query()
            ->where('episode_id', $consultation->episode_id)
            ->where('status', HospitalStayStatus::Active->value)
            ->exists();

        if (! $type->appliesTo($hospitalized)) {
            throw ValidationException::withMessages([
                'decision.type' => $hospitalized
                    ? "Ce patient est hospitalisé : « {$type->label()} » ne se choisit pas ici. Sa sortie se prononce sur la page du séjour."
                    : "« {$type->label()} » ne concerne qu’un patient hospitalisé.",
            ]);
        }
    }

    /**
     * La sortie : son type (sortie normale par défaut), puis ce que le médecin
     * a pu préciser. Le diagnostic final est celui du dossier, jamais ressaisi.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, ?string>  $prefill
     * @return array<string, mixed>
     */
    private function dischargeData(array $decision, array $prefill): array
    {
        $type = MedicalDischargeType::tryFrom((string) ($decision['discharge_type'] ?? '')) ?? MedicalDischargeType::Normal;

        if (! in_array($type, MedicalDischargeType::forConsultation(), true)) {
            throw ValidationException::withMessages([
                'decision.discharge_type' => 'Un transfert se décide par la conduite « Référence / Transfert », pas comme type de sortie.',
            ]);
        }

        $deceased = $type === MedicalDischargeType::Deceased;

        return [
            'type' => $type->value,
            'discharged_at' => $decision['discharged_at'] ?? now(),
            'final_diagnosis' => $prefill['diagnosis'],
            'patient_condition' => $deceased ? null : $this->text($decision['patient_condition'] ?? null),
            'discharge_prescription' => $deceased ? null : (array_key_exists('discharge_prescription', $decision)
                ? $this->text($decision['discharge_prescription'])
                : $prefill['treatments']),
            'recommendations' => $deceased ? null : $this->text($decision['recommendations'] ?? null),
            'follow_up_at' => $deceased ? null : ($decision['follow_up_at'] ?? null),
            'observations' => $this->text($decision['observations'] ?? null),
        ];
    }

    /** @param array<string, mixed> $decision */
    private function surgeryItem(array $decision): string
    {
        $uuid = $this->text($decision['catalog_item_uuid'] ?? null);

        if ($uuid === null) {
            throw ValidationException::withMessages([
                'decision.catalog_item_uuid' => 'Choisissez l’intervention envisagée : aucune demande n’arrive au bloc sans elle.',
            ]);
        }

        return $uuid;
    }

    /** @param array<string, ?string> $prefill */
    private function serviceReason(array $prefill, ?string $notes): ?string
    {
        $reason = collect([
            ['Motif', $prefill['reason']],
            ['Indication', $prefill['diagnosis']],
            ['Observations', $notes],
        ])->filter(fn (array $entry): bool => trim((string) $entry[1]) !== '')
            ->map(fn (array $entry): string => $entry[0].' : '.trim((string) $entry[1]))
            ->implode("\n");

        return $reason !== '' ? $reason : null;
    }

    private function join(?string ...$parts): ?string
    {
        $text = collect($parts)->filter(fn (?string $part): bool => trim((string) $part) !== '')->implode("\n");

        return $text !== '' ? $text : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
