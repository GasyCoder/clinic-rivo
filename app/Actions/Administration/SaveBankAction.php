<?php

namespace App\Actions\Administration;

use App\Models\Bank;
use App\Models\User;
use App\Support\Hr\BankName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — ajouter ou corriger une banque du référentiel.
 *
 * Un doublon est refusé ici aussi, pas seulement par la requête : « Bank of
 * Africa » n'entre pas quand « BOA — Bank of Africa Madagascar » existe, même
 * archivée (on la restaure plutôt que de la recréer).
 */
class SaveBankAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor, ?Bank $bank = null): Bank
    {
        Gate::forUser($actor)->authorize($bank ? 'update' : 'create', $bank ?? Bank::class);

        return DB::transaction(function () use ($data, $bank): Bank {
            $code = (string) ($data['code'] ?? $bank?->code ?? '');
            $name = (string) ($data['name'] ?? $bank?->name ?? '');
            $duplicate = BankName::duplicateOf($code, $name, $bank);

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => self::duplicateMessage($duplicate)]);
            }

            $bank ??= new Bank(['active' => true, 'position' => 0]);
            $bank->fill($data)->save();

            return $bank->refresh();
        });
    }

    public static function duplicateMessage(Bank $existing): string
    {
        return $existing->trashed()
            ? "Cette banque existe déjà, archivée : « {$existing->code} — {$existing->name} ». Restaurez-la plutôt que de la recréer."
            : "Cette banque existe déjà : « {$existing->code} — {$existing->name} ».";
    }
}
