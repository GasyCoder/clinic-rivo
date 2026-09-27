<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethodCategory;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodManager
{
    public function create(
        string $code,
        string $name,
        PaymentMethodCategory $category,
        bool $affectsCashBalance,
        bool $requiresReference = false,
    ): PaymentMethod {
        $code = $this->validatedCode($code);
        $name = $this->validatedName($name);

        return DB::transaction(function () use ($code, $name, $category, $affectsCashBalance, $requiresReference): PaymentMethod {
            $existing = PaymentMethod::query()->where('code', $code)->lockForUpdate()->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'code' => $existing->active
                        ? 'Ce code est déjà utilisé par un mode de paiement.'
                        : 'Ce code appartient à un mode désactivé. Réactivez-le au lieu d’en créer un second.',
                ]);
            }

            return PaymentMethod::query()->create([
                'code' => $code,
                'name' => $name,
                'category' => $category->value,
                'active' => true,
                'affects_cash_balance' => $affectsCashBalance,
                'requires_reference' => $requiresReference,
            ]);
        });
    }

    /**
     * `code` is deliberately immutable: it identifies the tender on payments
     * already recorded, in exports and in the operator-by-operator
     * reconciliation. Only the label and the cash-balance behaviour move.
     */
    public function update(
        PaymentMethod $method,
        string $name,
        PaymentMethodCategory $category,
        bool $affectsCashBalance,
        bool $requiresReference = false,
    ): PaymentMethod {
        $name = $this->validatedName($name);

        $method->update([
            'name' => $name,
            'category' => $category->value,
            'affects_cash_balance' => $affectsCashBalance,
            'requires_reference' => $requiresReference,
        ]);

        return $method->refresh();
    }

    public function activate(PaymentMethod $method): PaymentMethod
    {
        $method->update(['active' => true]);

        return $method->refresh();
    }

    /**
     * A method is never deleted — payments keep referencing it (ADR-010).
     * Deactivating the last active one would make every cash-in impossible,
     * so that single case is refused.
     */
    public function deactivate(PaymentMethod $method): PaymentMethod
    {
        return DB::transaction(function () use ($method): PaymentMethod {
            $method = PaymentMethod::query()->whereKey($method->getKey())->lockForUpdate()->firstOrFail();

            $remaining = PaymentMethod::query()
                ->where('active', true)
                ->whereKeyNot($method->getKey())
                ->count();

            if ($method->active && $remaining === 0) {
                throw ValidationException::withMessages([
                    'method' => 'Au moins un mode de paiement doit rester actif, sans quoi plus aucun encaissement n’est possible.',
                ]);
            }

            $method->update(['active' => false]);

            return $method->refresh();
        });
    }

    private function validatedCode(string $code): string
    {
        $code = str($code)->squish()->upper()->replace(' ', '_')->toString();

        if (! preg_match('/^[A-Z0-9_]{2,40}$/', $code)) {
            throw ValidationException::withMessages([
                'code' => 'Le code doit contenir de 2 à 40 caractères : lettres non accentuées, chiffres ou « _ ».',
            ]);
        }

        return $code;
    }

    private function validatedName(string $name): string
    {
        $name = str($name)->squish()->toString();

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages([
                'name' => 'Le libellé doit contenir entre 1 et 100 caractères.',
            ]);
        }

        return $name;
    }
}
