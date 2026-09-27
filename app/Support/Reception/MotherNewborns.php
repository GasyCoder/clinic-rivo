<?php

namespace App\Support\Reception;

use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\PatientNewbornLink;
use App\Support\NewbornFiche;

/**
 * ADR-146 — les nouveau-nés d'une mère : une seule définition, partout.
 *
 * « Accouchement chez nous » : la Réception cherche la mère, et voit ses bébés — qu'ils soient déjà patients
 * ou non. Le dossier permanent de la mère lit la même liste : un bébé né ici est un fait de son dossier,
 * qu'il ait ou non son propre numéro.
 *
 * Rien de clinique n'est servi : le nom, le rang, le sexe et la naissance suffisent à reconnaître l'enfant,
 * et servir davantage (poids, Apgar, soins) exigerait `newborns.medical_record.view`, que la Réception
 * ne reçoit pas d'office (ADR-146 amendement).
 * C'est une projection de données : les liens et les droits qui les gouvernent appartiennent à l'appelant.
 */
final class MotherNewborns
{
    /** @return list<array<string, mixed>> */
    public static function for(Patient $mother): array
    {
        $records = MaternityRecord::query()
            ->whereHas('episode', fn ($query) => $query->where('patient_id', $mother->getKey()))
            ->with('episode:id,uuid')
            ->orderByDesc('id')
            ->get();

        $links = PatientNewbornLink::query()
            ->whereIn('maternity_record_id', $records->pluck('id'))
            ->with('patient')
            ->get()
            ->keyBy(fn (PatientNewbornLink $link) => $link->maternity_record_id.'|'.$link->newborn_uuid);

        return $records->flatMap(function (MaternityRecord $record) use ($mother, $links): array {
            $bornAt = $record->delivery_data['occurred_at'] ?? null;

            return collect($record->newborn_data['newborns'] ?? [])
                ->map(function (array $newborn, int $index) use ($record, $mother, $links, $bornAt): ?array {
                    // Une fiche vide n'est pas un bébé consigné : elle n'a pas d'identité et n'est pas listée.
                    if (blank($newborn['uuid'] ?? null) || ! NewbornFiche::isFilled($newborn)) {
                        return null;
                    }

                    $patient = $links->get($record->getKey().'|'.$newborn['uuid'])?->patient;
                    $sexCode = in_array($newborn['sex'] ?? null, ['M', 'F'], true) ? $newborn['sex'] : null;

                    return [
                        'record_uuid' => $record->uuid,
                        // Le passage où le bébé est consigné : son dossier médical s'y lit tant qu'il
                        // n'est pas patient (ADR-146).
                        'episode_uuid' => $record->episode?->uuid,
                        'newborn_uuid' => $newborn['uuid'],
                        'rank' => $index + 1,
                        'name' => NewbornFiche::displayName($newborn, $mother->last_name, $index + 1),
                        'first_name' => filled($newborn['first_name'] ?? null) ? $newborn['first_name'] : null,
                        'last_name' => filled($newborn['last_name'] ?? null) ? $newborn['last_name'] : null,
                        'sex_code' => $sexCode,
                        'born_at' => $bornAt,
                        'patient' => $patient ? PatientSearchPayload::make($patient) : null,
                        // La naissance n'est jamais devinée : sans date d'accouchement, le patient ne peut pas être créé.
                        'blocked' => blank($bornAt) && $patient === null
                            ? 'La date de l’accouchement n’est pas consignée à la Maternité.'
                            : null,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        })->values()->all();
    }
}
