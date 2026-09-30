<?php

namespace App\Support\Hr;

use App\Enums\EmployeeRemunerationType;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * ADR-206 — la rémunération déclarée et le compte bancaire d'un dossier employé.
 *
 * Une seule règle pour la création et la modification : ces champs ne s'écrivent
 * qu'avec `employees.payroll.update` — revérifié ici, l'écran et la requête ne
 * sont jamais la seule garde — et un dossier « non rémunéré » n'a pas de montant.
 * Omettre ces champs les laisse tels quels.
 *
 * ADR-221 — la banque du compte se choisit dans le module Banques (`bank_uuid`) ;
 * une banque archivée depuis reste acceptée pour la fiche qui la porte déjà.
 */
final class EmployeePayroll
{
    public const FIELDS = ['remuneration_type', 'remuneration_amount', 'benefits_enabled', 'bank_uuid', 'bank_account_number', 'bank_account_holder', 'salary_payment_mode', 'mobile_money_accounts'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, User $actor, ?Employee $employee = null): array
    {
        if (array_intersect(self::FIELDS, array_keys($data)) === []) {
            return $data;
        }

        if (! $actor->can('employees.payroll.update')) {
            throw new AuthorizationException('Modifier la rémunération ou le compte bancaire demande le droit « employees.payroll.update ».');
        }

        if (array_key_exists('bank_uuid', $data)) {
            $data['bank_id'] = self::bank($data['bank_uuid'], $employee)?->getKey();
            unset($data['bank_uuid']);
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

    private static function bank(mixed $uuid, ?Employee $employee): ?Bank
    {
        if (blank($uuid)) {
            return null;
        }

        $bank = Bank::withTrashed()->where('uuid', (string) $uuid)->first();
        $keeps = $bank && $employee?->bank_id === $bank->getKey();

        if (! $bank || (! $bank->isAvailable() && ! $keeps)) {
            throw ValidationException::withMessages(['bank_uuid' => 'Cette banque n’est plus proposée : choisissez-en une autre dans la liste.']);
        }

        return $bank;
    }
}
