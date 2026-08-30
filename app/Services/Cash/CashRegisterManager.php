<?php

namespace App\Services\Cash;

use App\Models\CashRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterManager
{
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
