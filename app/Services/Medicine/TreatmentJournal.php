<?php

namespace App\Services\Medicine;

use App\Enums\SurgicalRequestStatus;
use App\Models\CareOrder;
use App\Models\CareRecordProcedure;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\HospitalStay;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\Prescription;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTreatmentItem;
use App\Models\TreatmentJournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-116 — le « Dossier médical – Traitement » d'un passage.
 *
 * La feuille papier de la clinique a trois colonnes : date et heure,
 * description, visa du personnel médical. Le journal les remplit de deux
 * sources :
 *
 *   automatique   ce qui est déjà enregistré dans le passage — actes de
 *                 soins, ordonnances, demandes, admission, sortie ; le visa
 *                 est l'auteur réel de l'acte
 *   manuelle      les lignes saisies par Médecine et Soins pour ce que
 *                 l'application n'enregistre pas (un traitement administré
 *                 au lit, par exemple)
 *
 * Rien n'est recopié : les lignes automatiques sont relues à chaque
 * affichage, si bien qu'une correction de l'acte d'origine se voit ici.
 * Chaque source n'apparaît qu'avec la permission qui la garde déjà ailleurs
 * (même règle que la page « Détail du passage », ADR-054).
 */
class TreatmentJournal
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Episode $episode, User $user): array
    {
        $rows = collect()
            ->merge($this->manualRows($episode))
            ->merge($user->can('consultations.view') ? $this->consultationRows($episode) : [])
            ->merge($user->can('care.view') ? $this->careRows($episode) : [])
            ->merge($user->can('care_orders.view') ? $this->careOrderRows($episode) : [])
            ->merge($user->can('prescriptions.view') ? $this->prescriptionRows($episode) : [])
            ->merge($user->can('laboratory_orders.view') ? $this->labRows($episode) : [])
            ->merge($user->can('imaging_orders.view') ? $this->imagingRows($episode) : [])
            ->merge($user->can('hospitalization.view') ? $this->hospitalRows($episode) : [])
            // ADR-172 — ce que le bloc a fait figure au journal, gardé par le
            // droit qui possède déjà le dossier du bloc.
            ->merge($user->can('surgery.view') ? $this->surgeryRows($episode) : [])
            ->merge($user->can('medical_record.view') ? $this->dischargeRows($episode) : []);

        return $rows
            ->filter(fn (array $row) => $row['occurred_at'] !== null)
            // Chronologique : c'est l'ordre dans lequel la feuille se lit.
            ->sortBy(fn (array $row) => sprintf('%s|%s', $row['occurred_at']->format('Y-m-d H:i:s'), $row['key']))
            ->values()
            ->map(fn (array $row) => [...$row, 'occurred_at' => $row['occurred_at']->toIso8601String()])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function manualRows(Episode $episode): Collection
    {
        return TreatmentJournalEntry::query()
            ->where('episode_id', $episode->getKey())
            ->with('recordedBy:id,name')
            ->get()
            ->map(fn (TreatmentJournalEntry $entry) => $this->row(
                key: "manual:{$entry->uuid}",
                occurredAt: $entry->occurred_at,
                source: 'MANUAL',
                sourceLabel: 'Saisie',
                html: $this->richText->displayHtml($entry->description),
                text: $this->richText->plainText($entry->description),
                visa: $entry->recordedBy?->name,
                manual: true,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function consultationRows(Episode $episode): Collection
    {
        return Consultation::query()
            ->where('episode_id', $episode->getKey())
            ->with('doctor:id,name')
            ->get()
            ->map(fn (Consultation $consultation) => $this->row(
                key: "consultation:{$consultation->uuid}",
                occurredAt: $consultation->consulted_at ?? $consultation->created_at,
                source: 'CONSULTATION',
                sourceLabel: 'Consultation',
                text: $this->sentence('Consultation médicale', $consultation->chief_complaint),
                visa: $consultation->doctor?->name,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function careRows(Episode $episode): Collection
    {
        return CareRecordProcedure::query()
            ->whereHas('careRecord', fn ($query) => $query->where('episode_id', $episode->getKey()))
            ->with('performer:id,name')
            ->get()
            ->map(fn (CareRecordProcedure $procedure) => $this->row(
                key: "care:{$procedure->uuid}",
                occurredAt: $procedure->performed_at ?? $procedure->created_at,
                source: 'CARE',
                sourceLabel: 'Soins',
                text: $this->sentence(
                    'Acte de soins : '.$procedure->procedure_name.$this->times($procedure->quantity),
                    $procedure->notes,
                ),
                visa: $procedure->performer?->name,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function careOrderRows(Episode $episode): Collection
    {
        return CareOrder::query()
            ->where('episode_id', $episode->getKey())
            ->with(['requestedBy:id,name', 'items'])
            ->get()
            ->map(fn (CareOrder $order) => $this->row(
                key: "care-order:{$order->uuid}",
                occurredAt: $order->ordered_at ?? $order->created_at,
                source: 'CARE_ORDER',
                sourceLabel: 'Demande de soins',
                text: $this->sentence(
                    'Soins demandés : '.$order->items
                        ->map(fn ($item) => $item->catalog_item_name_snapshot.$this->times($item->quantity)
                            .($item->cancelled_at ? ' (retiré)' : ''))
                        ->implode(', '),
                    $order->instructions,
                ),
                visa: $order->requestedBy?->name,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function prescriptionRows(Episode $episode): Collection
    {
        return Prescription::query()
            ->where('episode_id', $episode->getKey())
            ->with(['prescribedBy:id,name', 'lines'])
            ->get()
            ->map(function (Prescription $prescription) {
                $lines = $prescription->lines->map(function ($line) {
                    $posology = collect([
                        $line->dosage,
                        $line->route?->shortLabel(),
                        $line->frequency,
                        $line->duration,
                    ])->filter()->implode(' · ');

                    return $line->medication_name.($posology !== '' ? " ({$posology})" : '');
                })->implode(' ; ');

                $cancelled = $prescription->cancelled_at !== null;

                return $this->row(
                    key: "prescription:{$prescription->uuid}",
                    occurredAt: $prescription->prescribed_at ?? $prescription->created_at,
                    source: 'PRESCRIPTION',
                    sourceLabel: 'Ordonnance',
                    text: 'Ordonnance : '.($lines !== '' ? $lines : 'sans ligne').($cancelled ? ' — annulée' : ''),
                    visa: $prescription->prescribedBy?->name,
                );
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function labRows(Episode $episode): Collection
    {
        return LabRequest::query()
            ->where('episode_id', $episode->getKey())
            ->with(['requestedBy:id,name', 'items'])
            ->get()
            ->map(fn (LabRequest $request) => $this->row(
                key: "lab:{$request->uuid}",
                occurredAt: $request->requested_at ?? $request->created_at,
                source: 'LABORATORY',
                sourceLabel: 'Analyses',
                text: 'Analyses demandées : '.$request->items->pluck('catalog_item_name_snapshot')->implode(', ')
                    .($request->cancelled_at ? ' — demande retirée' : ''),
                visa: $request->requestedBy?->name,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function imagingRows(Episode $episode): Collection
    {
        return ImagingRequest::query()
            ->where('episode_id', $episode->getKey())
            ->with(['requestedBy:id,name', 'items'])
            ->get()
            ->map(fn (ImagingRequest $request) => $this->row(
                key: "imaging:{$request->uuid}",
                occurredAt: $request->requested_at ?? $request->created_at,
                source: 'IMAGING',
                sourceLabel: 'Imagerie',
                text: 'Examens demandés : '.$request->items->pluck('catalog_item_name_snapshot')->implode(', ')
                    .($request->cancelled_at ? ' — demande retirée' : ''),
                visa: $request->requestedBy?->name,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function hospitalRows(Episode $episode): Collection
    {
        return HospitalStay::query()
            ->where('episode_id', $episode->getKey())
            ->with('admittedBy:id,name')
            ->get()
            ->map(fn (HospitalStay $stay) => $this->row(
                key: "stay:{$stay->uuid}",
                occurredAt: $stay->admitted_at,
                source: 'HOSPITALIZATION',
                sourceLabel: 'Hospitalisation',
                text: $this->sentence('Admission en hospitalisation', $stay->service)
                    .($stay->cancelled_at ? ' — séjour annulé' : ''),
                visa: $stay->admittedBy?->name,
            ));
    }

    /**
     * ADR-172 — les faits du bloc, chacun à son heure réelle : entrée au bloc,
     * début et fin de l'intervention, sortie du bloc, traitements préliminaires
     * et postopératoires, complications, sortie de Chirurgie. Une demande
     * annulée n'a rien fait et n'écrit rien.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function surgeryRows(Episode $episode): Collection
    {
        return SurgicalRequest::query()
            ->where('episode_id', $episode->getKey())
            ->whereNot('status', SurgicalRequestStatus::Cancelled->value)
            ->with([
                'surgeon:id,name', 'dischargedBy:id,name',
                'intervention.performedBy:id,name', 'blockExit', 'blockEntry',
                'complications.reportedBy:id,name', 'treatmentItems.recordedBy:id,name',
            ])
            ->get()
            ->flatMap(function (SurgicalRequest $case): Collection {
                $procedure = $case->procedure_name ?: 'intervention';
                $rows = collect();

                if ($case->blockExit?->block_entered_at) {
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:block-entry",
                        occurredAt: $case->blockExit->block_entered_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: 'Entrée au bloc opératoire — '.$procedure,
                        visa: $case->surgeon?->name,
                    ));
                }

                if ($case->intervention?->started_at) {
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:intervention-start",
                        occurredAt: $case->intervention->started_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: $this->sentence('Début de l’intervention : '.$procedure, $case->intervention->procedure_summary),
                        visa: $case->intervention->performedBy?->name ?? $case->surgeon?->name,
                    ));
                }

                if ($case->intervention?->ended_at) {
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:intervention-end",
                        occurredAt: $case->intervention->ended_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: 'Fin de l’intervention : '.$procedure,
                        visa: $case->intervention->performedBy?->name ?? $case->surgeon?->name,
                    ));
                }

                if ($case->blockExit?->block_exited_at) {
                    $awakening = $case->blockExit->awakening_status?->label();
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:block-exit",
                        occurredAt: $case->blockExit->block_exited_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: $this->sentence('Sortie du bloc opératoire', $awakening ? 'réveil : '.$awakening : null),
                        visa: $case->surgeon?->name,
                    ));
                }

                foreach ($case->treatmentItems as $item) {
                    /** @var SurgicalTreatmentItem $item */
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:treatment:{$item->getKey()}",
                        occurredAt: $item->created_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: $this->sentence(
                            $item->phase?->label().' : '.$item->label.$this->times($item->quantity).($item->unit ? ' '.$item->unit : ''),
                            $item->category?->label(),
                        ),
                        visa: $item->recordedBy?->name,
                    ));
                }

                foreach ($case->complications as $complication) {
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:complication:{$complication->getKey()}",
                        occurredAt: $complication->reported_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: 'Complication : '.$complication->description,
                        visa: $complication->reportedBy?->name,
                    ));
                }

                if ($case->discharged_at) {
                    $rows->push($this->row(
                        key: "surgery:{$case->uuid}:discharge",
                        occurredAt: $case->discharged_at,
                        source: 'SURGERY',
                        sourceLabel: 'Bloc opératoire',
                        text: $this->sentence('Sortie de Chirurgie', $case->discharge_notes),
                        visa: $case->dischargedBy?->name,
                    ));
                }

                return $rows;
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function dischargeRows(Episode $episode): Collection
    {
        $discharge = $episode->medicalDischarge()->with('creator:id,name')->first();

        if ($discharge === null) {
            return collect();
        }

        return collect([$this->row(
            key: "discharge:{$discharge->getKey()}",
            occurredAt: $discharge->discharged_at,
            source: 'DISCHARGE',
            sourceLabel: 'Sortie médicale',
            text: $this->sentence('Sortie médicale : '.$discharge->type->label(), $discharge->patient_condition),
            visa: $discharge->creator?->name,
        )]);
    }

    /** @return array<string, mixed> */
    private function row(
        string $key,
        ?Carbon $occurredAt,
        string $source,
        string $sourceLabel,
        string $text,
        ?string $visa,
        ?string $html = null,
        bool $manual = false,
    ): array {
        return [
            'key' => $key,
            'occurred_at' => $occurredAt,
            'source' => $source,
            'source_label' => $sourceLabel,
            'description' => $text,
            'description_html' => $html,
            'visa' => $visa,
            'manual' => $manual,
        ];
    }

    /** « × 2 » seulement quand la quantité dit quelque chose ; jamais « × 1.00 ». */
    private function times(mixed $quantity): string
    {
        $value = (float) $quantity;

        if ($value <= 1) {
            return '';
        }

        return ' × '.rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    private function sentence(string $head, ?string $detail): string
    {
        $detail = trim((string) $detail);

        return $detail === '' ? $head : "{$head} — {$detail}";
    }
}
