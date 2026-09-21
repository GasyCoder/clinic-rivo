<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayEndReason;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\MedicalDischarge;
use App\Models\User;
use App\Support\Hospitalization\StayOrderContext;
use App\Support\Medicine\MedicalDischargeAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-162 — la sortie médicale d'un patient hospitalisé, prononcée sur la page
 * du séjour, et là seulement (amende l'emplacement posé par l'ADR-156, pas son
 * principe : il n'y a toujours qu'une sortie médicale par passage).
 *
 * Elle termine le séjour et la prise en charge dans la même transaction :
 * jamais un passage médicalement sorti dont le lit reste occupé. La
 * consultation qui a demandé l'hospitalisation n'est ni rouverte ni réécrite.
 */
class DischargeHospitalStayAction
{
    public function __construct(private readonly RecordHospitalStayDiagnosisAction $recordDiagnosis) {}

    /** @param array<string, mixed> $data */
    public function execute(HospitalStay $stay, array $data, User $actor): MedicalDischarge
    {
        return DB::transaction(function () use ($stay, $data, $actor): MedicalDischarge {
            $context = StayOrderContext::lock($stay, 'medical_discharge');
            $episode = $context->episode;

            if ($episode->medicalDischarge()->exists()) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Une sortie médicale a déjà été prononcée pour ce passage.',
                ]);
            }

            $type = MedicalDischargeType::from($data['type']);

            // ADR-161 — un patient au lit n'est transféré qu'au départ de
            // l'ambulance, constaté dans le module Transferts.
            if ($type === MedicalDischargeType::Transfer) {
                throw ValidationException::withMessages([
                    'type' => 'Un transfert se demande depuis le séjour (« Demander un transfert ») ; le séjour se terminera au départ du patient, constaté dans le module Transferts.',
                ]);
            }

            $attributes = MedicalDischargeAttributes::from($data, $type);
            $this->recordFinalDiagnosisIfNew($context->stay, $attributes['final_diagnosis'], $actor);

            $discharge = MedicalDischarge::query()->create([
                ...$attributes,
                'episode_id' => $episode->getKey(),
                'consultation_id' => null,
                'created_by' => $actor->getKey(),
            ]);

            $context->stay->update([
                'status' => HospitalStayStatus::Discharged,
                'discharged_at' => $discharge->discharged_at,
                'discharged_by' => $actor->getKey(),
                'medical_discharge_id' => $discharge->getKey(),
                'end_reason' => HospitalStayEndReason::fromDischargeType($type),
                'active_key' => null,
            ]);
            $context->stay->closeCurrentMovement($discharge->discharged_at);
            $context->orientation->complete($actor);

            $episode->medical_status = $type->medicalStatus();

            // Plus aucun service n'a le patient : la suite appartient à la
            // Réception (ADR-054, ADR-090). Un statut déjà avancé n'est jamais
            // ramené en arrière.
            if ($episode->administrative_status === EpisodeAdministrativeStatus::InCare
                && ! EpisodeOrientation::query()
                    ->where('episode_id', $episode->getKey())
                    ->whereIn('status', [EpisodeOrientationStatus::Pending->value, EpisodeOrientationStatus::InProgress->value])
                    ->exists()) {
                $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
            }

            $episode->save();

            return $discharge->fresh(['creator:id,name']);
        });
    }

    /**
     * Le diagnostic final rejoint le séjour s'il n'y est pas déjà consigné —
     * ni sur le séjour, ni dans une consultation du passage. Rien n'est
     * recopié deux fois, et la consultation close n'est jamais réécrite.
     *
     * Le formulaire compose le diagnostic final des diagnostics cochés, **une
     * ligne par diagnostic**. Chaque ligne est donc lue pour elle-même : la
     * liste entière n'est jamais enregistrée comme un diagnostic de plus, et
     * seule une ligne encore inconnue rejoint le séjour.
     */
    private function recordFinalDiagnosisIfNew(HospitalStay $stay, ?string $finalDiagnosis, User $actor): void
    {
        $lines = collect(preg_split('/\R/u', (string) $finalDiagnosis))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->unique(fn (string $line) => mb_strtolower($line));

        if ($lines->isEmpty()) {
            return;
        }

        $known = $stay->diagnoses()->pluck('description')
            ->concat(Diagnosis::query()
                ->whereHas('consultation', fn ($query) => $query->where('episode_id', $stay->episode_id))
                ->whereDoesntHave('cancellation')
                ->pluck('description'))
            ->map(fn (?string $description) => mb_strtolower(trim((string) $description)))
            ->flip();

        foreach ($lines as $line) {
            if (! $known->has(mb_strtolower($line))) {
                $this->recordDiagnosis->execute($stay, $line, $actor);
            }
        }
    }
}
