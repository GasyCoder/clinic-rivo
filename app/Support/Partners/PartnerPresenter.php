<?php

namespace App\Support\Partners;

use App\Models\PartnerOrganization;

/**
 * ADR-211 — une fiche partenaire, écrite une seule fois pour le module
 * Partenaires et pour l'accueil.
 *
 * Le dossier patient relié n'est servi qu'à qui peut voir les patients : la
 * fiche d'un partenaire ne doit pas dire à un autre service qui s'est fait
 * soigner ici.
 */
class PartnerPresenter
{
    /** @return array<string, mixed> */
    public static function row(PartnerOrganization $partner, bool $canViewPatients): array
    {
        $patient = $canViewPatients ? $partner->patient : null;

        return [
            'uuid' => $partner->uuid,
            'category' => $partner->category?->value ?? 'OTHER',
            'category_label' => $partner->category?->label() ?? 'Autre',
            'name' => $partner->name,
            'last_name' => $partner->last_name,
            'first_name' => $partner->first_name,
            'profession' => $partner->profession?->value,
            'profession_label' => $partner->professionLabel(),
            'profession_detail' => $partner->profession_detail,
            'sex' => $partner->sex?->value,
            'birth_date' => $partner->birth_date?->toDateString(),
            'phone' => $partner->phone,
            'email' => $partner->email,
            'address_entry_uuid' => $partner->addressEntry?->uuid,
            'address' => $partner->address,
            'notes' => $partner->notes,
            'active' => (bool) $partner->active,
            'archived' => $partner->trashed(),
            'delete_reason' => $partner->delete_reason,
            'has_patient' => $partner->patient_id !== null,
            'patient' => $patient ? [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'archived' => $patient->trashed(),
            ] : null,
            'episodes_count' => (int) ($partner->episode_coverages_count ?? 0),
        ];
    }
}
