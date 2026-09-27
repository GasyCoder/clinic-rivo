<?php

namespace App\Actions\User;

use App\Enums\UserPermissionSource;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncProfessionalProfilePermissionsAction
{
    /**
     * Synchronize provenance without participating in permission resolution.
     * The caller owns the surrounding user-update transaction and lock.
     *
     * @param  array<int, array{permission_id: int, effect: string}>|null  $manualOverrides
     * @return array{removed_profile_permissions: array<int, string>, added_profile_permissions: array<int, string>}
     */
    public function execute(
        User $user,
        ?ProfessionalProfile $oldProfile,
        ?ProfessionalProfile $newProfile,
        ?array $manualOverrides,
        bool $applyNewProfileRecommendations,
    ): array {
        $profileChanged = $oldProfile?->getKey() !== $newProfile?->getKey();
        $profileIdsToReplace = collect();

        if ($profileChanged && $oldProfile) {
            $profileIdsToReplace->push($oldProfile->getKey());
        }

        // Explicit application also refreshes recommendations when the
        // profile itself did not change, while preserving every MANUAL row.
        if ($applyNewProfileRecommendations && $newProfile) {
            $profileIdsToReplace->push($newProfile->getKey());
        }

        $profileIdsToReplace = $profileIdsToReplace->unique()->values();
        $profileRows = DB::table('user_permissions')
            ->where('user_id', $user->getKey())
            ->where('source', UserPermissionSource::Profile->value)
            ->when(
                $profileIdsToReplace->isNotEmpty(),
                fn ($query) => $query->whereIn('source_profile_id', $profileIdsToReplace),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->get(['permission_id']);
        $removedNames = Permission::query()
            ->whereIn('id', $profileRows->pluck('permission_id'))
            ->pluck('name', 'id');

        if ($profileRows->isNotEmpty()) {
            DB::table('user_permissions')
                ->where('user_id', $user->getKey())
                ->where('source', UserPermissionSource::Profile->value)
                ->whereIn('source_profile_id', $profileIdsToReplace)
                ->delete();
        }

        if ($manualOverrides !== null) {
            $desiredManual = collect($manualOverrides)->keyBy('permission_id');
            $manualQuery = DB::table('user_permissions')
                ->where('user_id', $user->getKey())
                ->where('source', UserPermissionSource::Manual->value);

            if ($desiredManual->isEmpty()) {
                $manualQuery->delete();
            } else {
                $manualQuery->whereNotIn('permission_id', $desiredManual->keys())->delete();
            }

            foreach ($desiredManual as $permissionId => $override) {
                DB::table('user_permissions')->upsert(
                    [[
                        'user_id' => $user->getKey(),
                        'permission_id' => $permissionId,
                        'effect' => $override['effect'],
                        'source' => UserPermissionSource::Manual->value,
                        'source_profile_id' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]],
                    ['user_id', 'permission_id'],
                    ['effect', 'source', 'source_profile_id', 'updated_at'],
                );
            }
        }

        $addedIds = collect();

        if ($applyNewProfileRecommendations && $newProfile) {
            $recommendationIds = $newProfile->recommendedPermissions()->pluck('permissions.id');

            foreach ($recommendationIds as $permissionId) {
                $existing = DB::table('user_permissions')
                    ->where('user_id', $user->getKey())
                    ->where('permission_id', $permissionId)
                    ->first(['source']);

                // A manual ALLOW or DENY is an explicit administrator
                // decision and always wins over the profile template.
                if ($existing?->source === UserPermissionSource::Manual->value) {
                    continue;
                }

                DB::table('user_permissions')->upsert(
                    [[
                        'user_id' => $user->getKey(),
                        'permission_id' => $permissionId,
                        'effect' => 'allow',
                        'source' => UserPermissionSource::Profile->value,
                        'source_profile_id' => $newProfile->getKey(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]],
                    ['user_id', 'permission_id'],
                    ['effect', 'source', 'source_profile_id', 'updated_at'],
                );
                $addedIds->push($permissionId);
            }
        }

        return [
            'removed_profile_permissions' => $profileRows
                ->map(fn ($row) => $removedNames->get($row->permission_id))
                ->filter()->sort()->values()->all(),
            'added_profile_permissions' => Permission::query()
                ->whereIn('id', $addedIds)
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        ];
    }
}
