<?php

namespace App\Services\Administration;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-168 — relier une fiche Employé au compte qui se connecte.
 *
 * `employees.user_id` existait (unique, facultatif) sans qu'aucun écran ne le
 * remplisse. Le lien sert à lire le planning RH d'un compte — la disponibilité
 * d'un chirurgien à la programmation du bloc. Il n'ouvre ni ne crée aucun
 * compte, et ne donne aucun droit : l'autorisation reste celle du compte.
 *
 * Un compte ne peut être relié qu'à une seule fiche, archives comprises : deux
 * fiches pour un même compte donneraient deux plannings contradictoires.
 */
class EmployeeAccountResolver
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, ?Employee $employee = null): array
    {
        if (! array_key_exists('user_uuid', $data)) {
            return $data;
        }

        $uuid = $data['user_uuid'];
        unset($data['user_uuid']);

        if (! $uuid) {
            $data['user_id'] = null;

            return $data;
        }

        $user = User::query()->where('uuid', $uuid)->first();

        if (! $user) {
            throw ValidationException::withMessages(['user_uuid' => 'Ce compte n’existe plus.']);
        }

        $unchanged = $employee && (int) $employee->user_id === (int) $user->id;

        // Un lien déjà posé reste valable si le compte a été désactivé depuis ;
        // un nouveau lien ne se pose que sur un compte actif.
        if (! $unchanged && (! $user->active || $user->deactivated_at)) {
            throw ValidationException::withMessages(['user_uuid' => "Le compte « {$user->name} » est désactivé."]);
        }

        $linkedElsewhere = Employee::withTrashed()
            ->where('user_id', $user->id)
            ->when($employee, fn ($query) => $query->whereKeyNot($employee->getKey()))
            ->first();

        if ($linkedElsewhere) {
            throw ValidationException::withMessages([
                'user_uuid' => "Le compte « {$user->name} » est déjà relié à la fiche {$linkedElsewhere->employee_number}".($linkedElsewhere->trashed() ? ' (archivée)' : '').'.',
            ]);
        }

        $data['user_id'] = $user->id;

        return $data;
    }
}
