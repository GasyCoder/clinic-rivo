<?php

namespace App\Actions\Bonus;

use App\Models\BonusCategory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-212 — créer ou corriger une catégorie de bonus : ce qu'elle compte, son
 * seuil mensuel, son montant fixe, et les membres du personnel concernés.
 *
 * Corriger une catégorie ne réécrit aucun bonus déjà validé : le nombre de
 * patients, le seuil et le montant y sont figés. Deux catégories ne portent
 * jamais le même nom, archives comprises (une archivée se restaure).
 */
class SaveBonusCategoryAction
{
    /**
     * @param  array{name: string, measure: string, threshold: int, amount: string|float, description?: string|null, employee_uuids?: list<string>}  $data
     */
    public function execute(?BonusCategory $category, array $data, User $actor): BonusCategory
    {
        $permission = $category ? 'bonus_categories.update' : 'bonus_categories.create';

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Vous ne pouvez pas '.($category ? 'modifier' : 'créer').' une catégorie de bonus.');
        }

        return DB::transaction(function () use ($category, $data, $actor): BonusCategory {
            if ($category) {
                $category = BonusCategory::query()->lockForUpdate()->findOrFail($category->getKey());
            }

            $category ??= new BonusCategory([
                'created_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('created', $actor),
            ]);
            $category->fill([
                'name' => $data['name'],
                'measure' => $data['measure'],
                'threshold' => (int) $data['threshold'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
            ]);

            $duplicate = BonusCategory::withTrashed()
                ->where('normalized_name', BonusCategory::normalize($category->name))
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => $duplicate->trashed()
                    ? "Une catégorie archivée s’appelle déjà « {$duplicate->name} » : restaurez-la."
                    : "Une catégorie s’appelle déjà « {$duplicate->name} »."]);
            }

            $category->save();

            if (array_key_exists('employee_uuids', $data)) {
                $ids = Employee::query()->whereIn('uuid', $data['employee_uuids'] ?? [])->where('active', true)->pluck('id');

                if ($ids->count() !== count(array_unique($data['employee_uuids'] ?? []))) {
                    throw ValidationException::withMessages(['employee_uuids' => 'Un membre du personnel choisi n’est plus en poste.']);
                }

                // Un employé qui a quitté son poste (inactif ou archivé) reste dans la
                // catégorie : ses bonus passés s'y rattachent, et il ne se choisit plus.
                $kept = $category->employees()->withTrashed()
                    ->where(fn ($query) => $query->where('employees.active', false)->orWhereNotNull('employees.deleted_at'))
                    ->pluck('employees.id');
                $category->employees()->sync($ids->merge($kept)->unique()->values()->all());
            }

            return $category;
        });
    }
}
