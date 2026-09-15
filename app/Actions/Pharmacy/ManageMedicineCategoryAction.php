<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineCategory;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — renaming, archiving and restoring a medicine family, for a local
 * account or the remote Super Admin. The code never changes (imports use it).
 * A family is archived only once no active medicine still belongs to it, so a
 * medicine on sale never loses its family.
 */
class ManageMedicineCategoryAction
{
    /** @param array{code: string, name: string, description?: ?string} $data */
    public function create(array $data, CatalogActor $actor): MedicineCategory
    {
        $this->authorize($actor, 'medicine_categories.create', 'créer');

        $code = mb_strtoupper(trim($data['code']));

        if (MedicineCategory::query()->withTrashed()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "Le code {$code} est déjà utilisé, peut-être par une famille archivée : restaurez-la plutôt."]);
        }

        return MedicineCategory::query()->create([
            'code' => $code,
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'created_by' => $actor->localUserId(),
            'updated_by' => $actor->localUserId(),
        ]);
    }

    /** @param array{name: string, description?: ?string} $data */
    public function update(MedicineCategory $category, array $data, CatalogActor $actor): MedicineCategory
    {
        $this->authorize($actor, 'medicine_categories.update', 'modifier');

        $category->update([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'updated_by' => $actor->localUserId() ?? $category->updated_by,
        ]);

        return $category;
    }

    public function archive(MedicineCategory $category, string $reason, CatalogActor $actor): MedicineCategory
    {
        $this->authorize($actor, 'medicine_categories.delete', 'archiver');

        return DB::transaction(function () use ($category, $reason): MedicineCategory {
            $category = MedicineCategory::query()->lockForUpdate()->findOrFail($category->id);
            $active = $category->medicines()->where('active', true)->count();

            if ($active > 0) {
                throw ValidationException::withMessages([
                    'category' => "{$active} médicament(s) actif(s) appartiennent encore à la famille {$category->name} : changez-les de famille ou désactivez-les d’abord.",
                ]);
            }

            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire (3 caractères au moins).']);
            }

            $category->delete_reason = trim($reason);
            $category->delete();

            return $category;
        });
    }

    public function restore(MedicineCategory $category, CatalogActor $actor): MedicineCategory
    {
        $this->authorize($actor, 'medicine_categories.restore', 'restaurer');

        $category->restore();

        return $category;
    }

    private function authorize(CatalogActor $actor, string $permission, string $verb): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException("Vous ne pouvez pas {$verb} cette famille de médicaments.");
        }
    }
}
