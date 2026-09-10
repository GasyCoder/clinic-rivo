<?php

namespace App\Services\Cash;

use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterManager
{
    public function __construct(private readonly Auditor $auditor) {}

    public function create(string $name): CashRegister
    {
        $name = $this->validatedName($name);

        return DB::transaction(function () use ($name): CashRegister {
            $existing = CashRegister::withTrashed()
                ->where('normalized_name', CashRegister::normalize($name))
                ->lockForUpdate()
                ->first();

            if ($existing?->trashed()) {
                throw ValidationException::withMessages([
                    'name' => 'Cette caisse est archivée. Restaurez-la au lieu d’en créer une nouvelle.',
                ]);
            }

            if ($existing) {
                throw ValidationException::withMessages([
                    'name' => 'Une caisse porte déjà ce nom.',
                ]);
            }

            return CashRegister::query()->create([
                'name' => $name,
                'active' => true,
            ]);
        });
    }

    public function update(CashRegister $register, string $name): CashRegister
    {
        $name = $this->validatedName($name);

        return DB::transaction(function () use ($register, $name): CashRegister {
            $duplicate = CashRegister::withTrashed()
                ->where('normalized_name', CashRegister::normalize($name))
                ->whereKeyNot($register->getKey())
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => $duplicate->trashed()
                        ? 'Une caisse identique est archivée. Restaurez-la au lieu de créer un doublon.'
                        : 'Une caisse porte déjà ce nom.',
                ]);
            }

            $register->update(['name' => $name]);

            return $register->refresh();
        });
    }

    /**
     * Tenders this desk accepts. An empty list means no restriction — every
     * active tender of the site stays accepted — which is what a desk that
     * was never configured has always done.
     *
     * @param  array<int, string>  $paymentMethodUuids
     */
    public function syncAcceptedPaymentMethods(
        CashRegister $register,
        array $paymentMethodUuids,
        ?User $actor = null,
    ): CashRegister {
        $uuids = collect($paymentMethodUuids)->filter()->unique()->values();

        return DB::transaction(function () use ($register, $uuids, $actor): CashRegister {
            $methods = PaymentMethod::query()->whereIn('uuid', $uuids)->get();

            if ($methods->count() !== $uuids->count()) {
                throw ValidationException::withMessages([
                    'payment_method_uuids' => 'Un mode de paiement sélectionné est introuvable sur ce site.',
                ]);
            }

            $inactive = $methods->reject(fn (PaymentMethod $method) => $method->active);

            if ($inactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'payment_method_uuids' => sprintf(
                        'Le mode « %s » est désactivé : réactivez-le avant de l’affecter à une caisse.',
                        $inactive->first()->name,
                    ),
                ]);
            }

            $before = $register->acceptedPaymentMethods()->pluck('payment_methods.code')->sort()->values()->all();
            $register->acceptedPaymentMethods()->sync($methods->pluck('id'));
            $after = $methods->pluck('code')->sort()->values()->all();

            $this->auditor->record(
                'cash_register.payment_methods.update',
                entity: $register,
                newValues: ['payment_methods' => $after],
                oldValues: ['payment_methods' => $before],
                module: 'cash',
                actor: $actor,
            );

            return $register->load('acceptedPaymentMethods');
        });
    }

    public function deactivate(CashRegister $register): CashRegister
    {
        return DB::transaction(function () use ($register): CashRegister {
            $register = CashRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if ($register->sessions()->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'register' => 'Cette caisse a une session ouverte. Clôturez-la avant de la désactiver.',
                ]);
            }

            $register->active = false;
            $register->save();

            return $register->refresh();
        });
    }

    public function activate(CashRegister $register): CashRegister
    {
        $register->active = true;
        $register->save();

        return $register->refresh();
    }

    public function archive(CashRegister $register, string $reason): void
    {
        $reason = str($reason)->squish()->toString();

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif d’archivage doit contenir entre 5 et 500 caractères.',
            ]);
        }

        DB::transaction(function () use ($register, $reason): void {
            $register = CashRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if ($register->sessions()->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'register' => 'Cette caisse a une session ouverte. Clôturez-la avant de l’archiver.',
                ]);
            }

            $register->active = false;
            $register->delete_reason = $reason;
            $register->save();
            $register->delete();
        });
    }

    public function restore(CashRegister $register): CashRegister
    {
        $conflict = CashRegister::query()
            ->where('normalized_name', $register->normalized_name)
            ->whereKeyNot($register->getKey())
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'register' => 'Une caisse active porte déjà ce nom. La restauration est impossible.',
            ]);
        }

        $register->active = true;
        $register->restore();
        $register->save();

        return $register->refresh();
    }

    private function validatedName(string $name): string
    {
        $name = str($name)->squish()->toString();

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Le nom de la caisse doit contenir entre 1 et 255 caractères.',
            ]);
        }

        return $name;
    }
}
