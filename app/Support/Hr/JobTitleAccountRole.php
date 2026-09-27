<?php

namespace App\Support\Hr;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * ADR-199 — le rôle (et le profil métier) qu'une fonction propose au compte de
 * celui qui l'exerce.
 *
 * Il est réglé sur la fonction, dans le module Fonctions (ADR-188), et rangé
 * dans ses métadonnées par **code** : le code d'un rôle ou d'un profil est son
 * identité, il ne change jamais (ADR-100). Ce n'est qu'une proposition : celui
 * qui crée le compte la voit préremplie et la change s'il le faut. Aucun droit
 * n'est jamais accordé par une fonction.
 *
 * Une instance lit les rôles du site une seule fois : une liste d'employés ne
 * coûte pas une requête par ligne.
 */
final class JobTitleAccountRole
{
    public const ROLE_KEY = 'account_role';

    public const PROFILE_KEY = 'account_profile';

    /** @var Collection<string, Role>|null */
    private ?Collection $roles = null;

    /**
     * Retire des données saisies le rôle et le profil proposés, et les range dans
     * les métadonnées de la fonction. Une donnée sans ces clés laisse le réglage
     * tel quel (omettre n'efface pas, ADR-074) ; un rôle vide dit « aucun ».
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $metadata
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null} les données sans ces clés, et les métadonnées à écrire (`null` : inchangées)
     */
    public static function takeFrom(array $data, ?array $metadata): array
    {
        if (! array_key_exists('account_role_code', $data) && ! array_key_exists('account_profile_code', $data)) {
            return [$data, null];
        }

        $role = $data['account_role_code'] ?? null;
        $profile = $role ? ($data['account_profile_code'] ?? null) : null;
        unset($data['account_role_code'], $data['account_profile_code']);

        return [$data, [
            ...($metadata ?? []),
            self::ROLE_KEY => $role ?: null,
            self::PROFILE_KEY => $profile ?: null,
        ]];
    }

    /**
     * Ce que la fonction de cet employé propose, résolu sur les rôles de ce site ;
     * `null` quand elle ne propose rien, ou un rôle qui n'existe plus ici.
     *
     * @return array{role_id: int, role_code: string, role_name: string, profile_id: int|null, profile_code: string|null, profile_name: string|null, job_title: string}|null
     */
    public function forEmployee(Employee $employee): ?array
    {
        return $this->forJobTitle($employee->jobTitle);
    }

    /** @return array<string, mixed>|null */
    public function forJobTitle(?HrReferenceValue $jobTitle): ?array
    {
        if (! $jobTitle || $jobTitle->type !== HrReferenceType::JobTitle) {
            return null;
        }

        $roleCode = $jobTitle->metadata[self::ROLE_KEY] ?? null;
        $role = is_string($roleCode) ? $this->roles()->get($roleCode) : null;

        if (! $role) {
            return null;
        }

        $profileCode = $jobTitle->metadata[self::PROFILE_KEY] ?? null;
        $profile = is_string($profileCode)
            ? $role->professionalProfiles->first(fn (ProfessionalProfile $candidate) => $candidate->code === $profileCode)
            : null;

        return [
            'role_id' => $role->id,
            'role_code' => $role->code,
            'role_name' => $role->name,
            'profile_id' => $profile?->id,
            'profile_code' => $profile?->code,
            'profile_name' => $profile?->name,
            'job_title' => $jobTitle->label,
        ];
    }

    /**
     * Les rôles qu'une fonction peut proposer, et leurs profils : ceux qu'un
     * compte du site peut recevoir. Jamais `SUPER_ADMIN`, qui ne vit que sur le
     * portail (ADR-027).
     *
     * @return list<array{code: string, name: string, profiles: list<array{code: string, name: string}>}>
     */
    public static function options(): array
    {
        return Role::query()
            ->where('code', '!=', 'SUPER_ADMIN')
            ->with(['professionalProfiles' => fn ($query) => $query->active()->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'code' => $role->code,
                'name' => $role->name,
                'profiles' => $role->professionalProfiles
                    ->map(fn (ProfessionalProfile $profile) => ['code' => $profile->code, 'name' => $profile->name])
                    ->values()->all(),
            ])
            ->values()->all();
    }

    /** @return Collection<string, Role> */
    private function roles(): Collection
    {
        return $this->roles ??= Role::query()
            ->where('code', '!=', 'SUPER_ADMIN')
            ->with(['professionalProfiles' => fn ($query) => $query->active()])
            ->get()
            ->keyBy('code');
    }
}
