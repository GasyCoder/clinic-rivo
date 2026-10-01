<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethodCategory;
use App\Models\Bank;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodManager
{
    public function create(
        string $code,
        string $name,
        PaymentMethodCategory $category,
        ?Bank $bank,
        bool $affectsCashBalance,
        bool $requiresReference = false,
        ?string $categoryDetail = null,
    ): PaymentMethod {
        $code = $this->validatedCode($code);
        $bank = $this->validatedBank($category, $bank);
        $name = $this->resolvedName($name, $bank);
        $categoryDetail = $this->validatedCategoryDetail($category, $categoryDetail);

        return DB::transaction(function () use ($code, $name, $category, $bank, $categoryDetail, $affectsCashBalance, $requiresReference): PaymentMethod {
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
                'bank_id' => $bank?->getKey(),
                'category_detail' => $categoryDetail,
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
        ?Bank $bank,
        bool $affectsCashBalance,
        bool $requiresReference = false,
        ?string $categoryDetail = null,
    ): PaymentMethod {
        $bank = $this->validatedBank($category, $bank, $method);
        $name = $this->resolvedName($name, $bank);
        $categoryDetail = $this->validatedCategoryDetail($category, $categoryDetail, $method);

        $method->update([
            'name' => $name,
            'category' => $category->value,
            'bank_id' => $bank?->getKey(),
            'category_detail' => $categoryDetail,
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

    /**
     * ADR-239 — un mode « Banque » désigne une banque active du référentiel du
     * site. Seule exception : un mode générique déjà en service sans banque
     * (« Chèque », « Virement bancaire ») se corrige sans en choisir une —
     * lui en imposer une inventerait une donnée.
     */
    private function validatedBank(PaymentMethodCategory $category, ?Bank $bank, ?PaymentMethod $existing = null): ?Bank
    {
        if ($category !== PaymentMethodCategory::Bank) {
            return null;
        }

        if ($bank === null && $existing !== null && $existing->category === PaymentMethodCategory::Bank && $existing->bank_id === null) {
            return null;
        }

        // Une banque archivée depuis reste acceptée pour le mode qui la porte déjà.
        $keepsItsBank = $bank !== null && $existing !== null && $existing->bank_id === $bank->getKey();

        if (! $bank || (! $bank->isAvailable() && ! $keepsItsBank)) {
            throw ValidationException::withMessages([
                'bank_uuid' => 'Choisissez une banque active dans le référentiel du site.',
            ]);
        }

        return $bank;
    }

    /** Un libellé laissé vide pour un mode « Banque » prend le nom de la banque. */
    private function resolvedName(?string $name, ?Bank $bank): string
    {
        if ($bank !== null && blank(str((string) $name)->squish()->toString())) {
            return $this->validatedName("{$bank->code} — {$bank->name}");
        }

        return $this->validatedName((string) $name);
    }

    /**
     * ADR-239 — « Autre » se nomme (« Carte bancaire », « Bon d'achat »…) ; les
     * autres catégories n'en ont pas besoin. Le mode générique « Autre » déjà en
     * service sans précision se corrige sans qu'on lui en invente une.
     */
    private function validatedCategoryDetail(PaymentMethodCategory $category, ?string $detail, ?PaymentMethod $existing = null): ?string
    {
        if ($category !== PaymentMethodCategory::Other) {
            return null;
        }

        $detail = str((string) $detail)->squish()->toString();

        if ($detail === '' && $existing !== null && $existing->category === PaymentMethodCategory::Other && blank($existing->category_detail)) {
            return null;
        }

        if (mb_strlen($detail) < 2 || mb_strlen($detail) > 60) {
            throw ValidationException::withMessages([
                'category_detail' => 'Précisez la catégorie de ce mode (2 à 60 caractères), par exemple « Carte bancaire ».',
            ]);
        }

        return $detail;
    }
}
