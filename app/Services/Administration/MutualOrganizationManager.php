<?php

namespace App\Services\Administration;

use App\Models\MutualOrganization;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class MutualOrganizationManager
{
    public function create(string $name, string|int $coverageRate = '100.00'): MutualOrganization
    {
        $name = $this->validatedName($name);
        $coverageRate = $this->validatedCoverageRate($coverageRate);

        return DB::transaction(function () use ($name, $coverageRate): MutualOrganization {
            $existing = MutualOrganization::withTrashed()
                ->where('normalized_name', MutualOrganization::normalize($name))
                ->lockForUpdate()
                ->first();

            if ($existing?->trashed()) {
                throw ValidationException::withMessages([
                    'name' => 'Cet organisme est archivé. Restaurez-le au lieu de créer un doublon.',
                ]);
            }

            if ($existing) {
                throw ValidationException::withMessages([
                    'name' => 'Cet organisme existe déjà dans le référentiel.',
                ]);
            }

            return MutualOrganization::query()->create([
                'name' => $name,
                'coverage_rate' => $coverageRate,
                'active' => true,
            ]);
        });
    }

    public function update(
        MutualOrganization $organization,
        string $name,
        string|int|null $coverageRate = null,
    ): MutualOrganization {
        $name = $this->validatedName($name);
        $coverageRate = $this->validatedCoverageRate($coverageRate ?? $organization->coverage_rate);

        return DB::transaction(function () use ($organization, $name, $coverageRate): MutualOrganization {
            $duplicate = MutualOrganization::withTrashed()
                ->where('normalized_name', MutualOrganization::normalize($name))
                ->whereKeyNot($organization->getKey())
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => $duplicate->trashed()
                        ? 'Un organisme identique est archivé. Restaurez-le au lieu de créer un doublon.'
                        : 'Cet organisme existe déjà dans le référentiel.',
                ]);
            }

            $organization->update([
                'name' => $name,
                'coverage_rate' => $coverageRate,
            ]);

            return $organization->refresh();
        });
    }

    public function archive(MutualOrganization $organization, string $reason): void
    {
        $reason = str($reason)->squish()->toString();

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif d’archivage doit contenir entre 5 et 500 caractères.',
            ]);
        }

        $organization->active = false;
        $organization->delete_reason = $reason;
        $organization->save();
        $organization->delete();
    }

    public function restore(MutualOrganization $organization): MutualOrganization
    {
        $conflict = MutualOrganization::query()
            ->where('normalized_name', $organization->normalized_name)
            ->whereKeyNot($organization->getKey())
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'organization' => 'Un organisme actif identique existe déjà. La restauration est impossible.',
            ]);
        }

        $organization->active = true;
        $organization->restore();
        $organization->save();

        return $organization->refresh();
    }

    private function validatedName(string $name): string
    {
        $name = str($name)->squish()->toString();

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Le nom de l’organisme doit contenir entre 1 et 255 caractères.',
            ]);
        }

        return $name;
    }

    private function validatedCoverageRate(string|int $rate): string
    {
        $normalized = trim((string) $rate);

        try {
            $basisPoints = Money::toMinor($normalized);
        } catch (InvalidArgumentException|OverflowException) {
            $basisPoints = -1;
        }

        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw ValidationException::withMessages([
                'coverage_rate' => 'Le taux de couverture doit être compris entre 0 et 100 % avec deux décimales maximum.',
            ]);
        }

        return Money::fromMinor($basisPoints);
    }
}
