<?php

namespace App\Actions\Administration;

use App\Models\MutualOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveMutualOrganizationAction
{
    public function execute(string $name, User $actor): MutualOrganization
    {
        $name = str($name)->squish()->toString();

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'mutual_organization_name' => 'Le nom de la mutuelle est invalide.',
            ]);
        }

        return DB::transaction(function () use ($name, $actor): MutualOrganization {
            $organization = MutualOrganization::withTrashed()
                ->where('normalized_name', MutualOrganization::normalize($name))
                ->lockForUpdate()
                ->first();

            if ($organization?->trashed() || ($organization && ! $organization->active)) {
                throw ValidationException::withMessages([
                    'mutual_organization_name' => 'Cette mutuelle est archivée ou inactive.',
                ]);
            }

            if ($organization) {
                if ($actor->cannot('mutual_organizations.view')) {
                    throw new AuthorizationException('Vous ne pouvez pas utiliser ce référentiel de mutuelles.');
                }

                return $organization;
            }

            if ($actor->cannot('mutual_organizations.create')) {
                throw new AuthorizationException('Vous ne pouvez pas ajouter une mutuelle au référentiel.');
            }

            return MutualOrganization::create(['name' => $name, 'active' => true]);
        });
    }
}
