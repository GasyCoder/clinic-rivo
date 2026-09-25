<?php

namespace App\Services\Administration;

use App\Models\Employee;
use App\Models\HrReferenceValue;
use Illuminate\Validation\ValidationException;

/**
 * ADR-194 — une fonction n'existe que dans les départements où le module
 * Fonctions la relie (ADR-188) : « Gardien » n'est pas une fonction du Laboratoire.
 *
 * Deux tolérances, et pas davantage :
 *   - une fonction reliée à aucun département reste permise partout, pour
 *     qu'aucune fonction ne disparaisse avant d'avoir été réglée ;
 *   - le couple déjà enregistré sur un dossier n'est pas refusé à la
 *     correction d'un autre champ : l'histoire d'un dossier ne bloque pas
 *     la mise à jour de son téléphone. Changer l'un des deux exige un couple
 *     cohérent.
 */
class JobTitleDepartmentGuard
{
    public function allows(?HrReferenceValue $department, ?HrReferenceValue $jobTitle): bool
    {
        if (! $department || ! $jobTitle) {
            return true;
        }

        $allowed = $jobTitle->departments()->withTrashed()->pluck('hr_reference_values.id');

        return $allowed->isEmpty() || $allowed->contains($department->getKey());
    }

    public function ensure(
        ?HrReferenceValue $department,
        ?HrReferenceValue $jobTitle,
        ?Employee $current = null,
        string $field = 'job_title_uuid',
    ): void {
        if ($current
            && $department?->getKey() === $current->department_id
            && $jobTitle?->getKey() === $current->job_title_id) {
            return;
        }

        if (! $this->allows($department, $jobTitle)) {
            throw ValidationException::withMessages([$field => $this->message($department, $jobTitle)]);
        }
    }

    public function message(HrReferenceValue $department, HrReferenceValue $jobTitle): string
    {
        return "La fonction « {$jobTitle->label} » n’existe pas dans le département « {$department->label} ». "
            .'Choisissez une fonction de ce département, ou reliez-la à ce département dans le module Fonctions.';
    }
}
