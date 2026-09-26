<?php

namespace App\Support\Hr;

use App\Enums\EmployeeRemunerationType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ADR-197 — la rémunération déclarée et le compte bancaire d'un dossier employé.
 *
 * Une seule règle pour la création et la modification : ces champs ne s'écrivent
 * qu'avec `employees.payroll.update` — revérifié ici, l'écran et la requête ne
 * sont jamais la seule garde — et un dossier « non rémunéré » n'a pas de montant.
 * Omettre ces champs les laisse tels quels.
 */
final class EmployeePayroll
{
    public const FIELDS = ['remuneration_type', 'remuneration_amount', 'bank_account_number', 'bank_account_holder'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, User $actor): array
    {
        if (array_intersect(self::FIELDS, array_keys($data)) === []) {
            return $data;
        }

        if (! $actor->can('employees.payroll.update')) {
            throw new AuthorizationException('Modifier la rémunération ou le compte bancaire demande le droit « employees.payroll.update ».');
        }

        if (array_key_exists('remuneration_type', $data)) {
            $type = $data['remuneration_type'] instanceof EmployeeRemunerationType
                ? $data['remuneration_type']
                : EmployeeRemunerationType::tryFrom((string) $data['remuneration_type']);

            $data['remuneration_type'] = $type?->value;

            if (! $type?->hasAmount()) {
                $data['remuneration_amount'] = null;
            }
        }

        return $data;
    }
}
