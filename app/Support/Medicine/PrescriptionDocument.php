<?php

namespace App\Support\Medicine;

use App\Enums\AdministrationRoute;
use App\Enums\PrescriptionStatus;
use App\Models\Episode;
use App\Models\Prescription;

/**
 * Une ordonnance écrite hors d'une consultation — au séjour (ADR-162) ou en
 * Maternité (ADR-205) —, telle que l'écran la liste et telle qu'elle s'imprime.
 *
 * Écrite une fois : le séjour et la Maternité montrent la même ordonnance de
 * la même façon, et l'impression sort la même feuille.
 */
final class PrescriptionDocument
{
    /** Ce que la liste lit, en une requête. */
    public const RELATIONS = [
        'prescribedBy:id,name',
        'pharmacyDispense:id,prescription_id,status,invoice_id',
    ];

    /**
     * Une ordonnance déjà facturée par la Pharmacie ne s'annule plus (même
     * règle qu'en consultation) : `$canCancel` dit seulement si le compte et le
     * dossier le permettent, le reste se lit sur l'ordonnance.
     *
     * @return array<string, mixed>
     */
    public static function listItem(Prescription $prescription, bool $canCancel, ?string $printUrl): array
    {
        $routes = collect(AdministrationRoute::cases())->mapWithKeys(fn (AdministrationRoute $route) => [$route->value => $route->shortLabel()]);
        $active = $prescription->status === PrescriptionStatus::Active;

        return [
            'uuid' => $prescription->uuid,
            'status' => $prescription->status->value,
            'prescribed_at' => $prescription->prescribed_at,
            'prescribed_by' => $prescription->prescribedBy?->name,
            'cancel_reason' => $prescription->cancel_reason,
            'dispense_status' => $prescription->pharmacyDispense?->status?->value,
            'dispense_status_label' => $prescription->pharmacyDispense?->status?->label(),
            'lines' => $prescription->lines->sortBy('id')->map(fn ($line): array => [
                'id' => $line->getKey(),
                'name' => $line->medication_name,
                'is_manual' => (bool) $line->is_manual_entry,
                'quantity' => $line->quantity,
                'posology' => collect([$line->dosage, $routes[$line->route?->value] ?? null, $line->frequency, $line->duration])
                    ->filter()->implode(' · '),
                'instructions' => $line->instructions,
            ])->values()->all(),
            'can_cancel' => $canCancel && $active && $prescription->pharmacyDispense?->invoice_id === null,
            'print_url' => $active ? $printUrl : null,
        ];
    }

    /**
     * Les données de la feuille imprimée (`Medicine/PrescriptionPrint`).
     *
     * @return array<string, mixed>
     */
    public static function printPage(Prescription $prescription, Episode $episode, string $backHref): array
    {
        $prescription->loadMissing(['prescribedBy:id,name,professional_profile_id', 'prescribedBy.professionalProfile:id,code', 'lines' => fn ($query) => $query->orderBy('id'), 'lines.medicine.catalogItem:id,unit']);
        $episode->loadMissing('patient');
        $patient = $episode->patient;

        return [
            'orientation' => ['uuid' => null],
            'backHref' => $backHref,
            'episode' => [
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
            ],
            'patient' => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex' => $patient->sex->value,
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
            ],
            'prescription' => [
                'uuid' => $prescription->uuid,
                'prescribed_at' => $prescription->prescribed_at ?? $prescription->created_at,
                'prescribed_by' => $prescription->prescribedBy?->name,
                'prescriber_title' => self::prescriberTitle($prescription),
                'lines' => $prescription->lines->map(fn ($line) => [
                    'id' => $line->getKey(),
                    'medication_name' => $line->medication_name,
                    'quantity' => $line->quantity,
                    'unit' => $line->medicine?->catalogItem?->unit,
                    'dosage' => $line->dosage,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'instructions' => $line->instructions,
                ])->values(),
            ],
        ];
    }

    /**
     * ADR-205 — une sage-femme n'est pas « Dr » : sa feuille porte son titre.
     * `null` garde l'écriture d'un médecin, la seule que la feuille connaissait.
     * Le profil décrit la fonction, il n'accorde rien (ADR-033).
     */
    private static function prescriberTitle(Prescription $prescription): ?string
    {
        return $prescription->prescribedBy?->professionalProfile?->code === 'MIDWIFE' ? 'Sage-femme' : null;
    }
}
