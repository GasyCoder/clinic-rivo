<?php

namespace App\Services\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * La session de caisse dans laquelle un encaissement entre : celle du poste désigné,
 * ouverte, et ouverte par celui qui encaisse (ADR-058, ADR-059). Écrite une fois :
 * l'encaissement d'une facture et celui d'un remboursement de dette (ADR-228) suivent
 * la même règle. À appeler dans la transaction de l'encaissement : la session est
 * verrouillée.
 *
 * Un poste désigné l'emporte. Sans poste, la session se résout seulement si elle est
 * sans ambiguïté : une seule ouverte par ce compte. Une caisse ouverte par quelqu'un
 * d'autre n'est jamais utilisable, même si c'est la seule du site. Une caisse
 * verrouillée par la Super Administration (ADR-057) n'est pas ouverte.
 */
final class OwnOpenCashSession
{
    public function resolve(?string $cashRegisterUuid, User $actor, string $missingMessage = 'Ouvrez la caisse avant d’enregistrer un paiement.'): CashSession
    {
        if ($cashRegisterUuid !== null) {
            $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->first();

            if (! $register) {
                throw ValidationException::withMessages([
                    'cash_register_uuid' => 'Cette caisse n’est plus disponible.',
                ]);
            }

            $session = CashSession::query()
                ->where('active_key', CashSession::activeKeyFor($register))
                ->where('status', CashSessionStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => "La caisse « {$register->name} » n’est pas ouverte.",
                ]);
            }

            if ($session->opened_by !== $actor->id) {
                throw ValidationException::withMessages([
                    'cash_register_uuid' => 'Cette caisse est utilisée par une autre personne. Utilisez une autre caisse disponible.',
                ]);
            }

            return $session;
        }

        $openSessions = CashSession::query()
            ->where('status', CashSessionStatus::Open->value)
            ->where('opened_by', $actor->id)
            ->whereNotNull('active_key')
            ->lockForUpdate()
            ->get();

        if ($openSessions->isEmpty()) {
            throw ValidationException::withMessages(['cash_session' => $missingMessage]);
        }

        if ($openSessions->count() > 1) {
            throw ValidationException::withMessages([
                'cash_register_uuid' => 'Plusieurs caisses sont ouvertes. Choisissez la caisse concernée.',
            ]);
        }

        return $openSessions->first();
    }
}
