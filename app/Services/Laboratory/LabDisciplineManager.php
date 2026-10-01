<?php

namespace App\Services\Laboratory;

use App\Models\AnalysisCatalog;
use App\Models\LabDiscipline;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-238 — le référentiel des disciplines du laboratoire. Rien n'est
 * supprimé : une discipline s'archive avec un motif (seulement quand plus
 * aucune analyse ne la porte), se restaure, ou se fusionne avec une autre —
 * le geste qui répare une faute de frappe (« BIOCHIME » dans « BIOCHIMIE »).
 * Un nom déjà porté, archives comprises, ne se recrée pas.
 */
class LabDisciplineManager
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User|CatalogActor $actor): LabDiscipline
    {
        $this->authorize($actor, 'lab_disciplines.create');

        return DB::transaction(function () use ($data, $actor): LabDiscipline {
            $name = $this->name($data['name'] ?? '');
            $this->ensureUnique($name);

            return LabDiscipline::query()->create([
                'name' => $name,
                'display_order' => array_key_exists('display_order', $data) && $data['display_order'] !== null
                    ? (int) $data['display_order']
                    : ((int) LabDiscipline::withTrashed()->max('display_order')) + 10,
                'is_active' => true,
                'created_by' => $this->userId($actor),
            ]);
        });
    }

    /**
     * Le nom choisi dans la fiche d'une analyse : la discipline de ce nom si
     * elle existe et est en service, sinon une nouvelle — jamais un doublon.
     */
    public function findOrCreate(string $name, User|CatalogActor $actor, string $errorKey = 'new_discipline_name'): LabDiscipline
    {
        $name = $this->name($name, $errorKey);
        $existing = LabDiscipline::withTrashed()->where('normalized_name', LabDiscipline::normalize($name))->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                throw ValidationException::withMessages([$errorKey => "La discipline « {$existing->name} » existe, archivée : restaurez-la depuis le Laboratoire › Disciplines."]);
            }

            return $existing;
        }

        if ($actor->cannot('lab_disciplines.create')) {
            throw ValidationException::withMessages([$errorKey => 'Ajouter une discipline demande le droit « lab_disciplines.create ». Choisissez-en une de la liste.']);
        }

        return $this->create(['name' => $name], $actor);
    }

    /** @param  array<string, mixed>  $data */
    public function update(LabDiscipline $discipline, array $data, User|CatalogActor $actor): LabDiscipline
    {
        $this->authorize($actor, 'lab_disciplines.update');

        return DB::transaction(function () use ($discipline, $data, $actor): LabDiscipline {
            $locked = LabDiscipline::query()->lockForUpdate()->findOrFail($discipline->getKey());
            $name = array_key_exists('name', $data) ? $this->name($data['name']) : $locked->name;
            $this->ensureUnique($name, $locked);

            $locked->update([
                'name' => $name,
                'display_order' => array_key_exists('display_order', $data) && $data['display_order'] !== null ? (int) $data['display_order'] : $locked->display_order,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $locked->is_active,
                'updated_by' => $this->userId($actor),
            ]);

            // La copie du nom sur les analyses suit le nouveau nom.
            if ($locked->wasChanged('name')) {
                AnalysisCatalog::withTrashed()->where('lab_discipline_id', $locked->id)->update(['exam_category' => $locked->name]);
            }

            return $locked->fresh();
        });
    }

    public function archive(LabDiscipline $discipline, string $reason, User|CatalogActor $actor): void
    {
        $this->authorize($actor, 'lab_disciplines.archive');

        $count = AnalysisCatalog::query()->where('lab_discipline_id', $discipline->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['reason' => "{$count} analyse(s) portent encore cette discipline : fusionnez-la avec une autre, ou changez leur discipline d’abord."]);
        }

        $discipline->deleted_by = $this->userId($actor);
        $discipline->delete_reason = trim($reason);
        $discipline->delete();
    }

    public function restore(LabDiscipline $discipline, User|CatalogActor $actor): void
    {
        $this->authorize($actor, 'lab_disciplines.restore');
        $this->ensureUnique($discipline->name, $discipline);
        $discipline->restore();
    }

    /**
     * Toutes les analyses de `$source` passent dans `$target`, puis `$source`
     * s'archive avec le motif de la fusion. Les comptes rendus déjà rendus ne
     * bougent pas : seule la discipline des analyses au catalogue change.
     */
    public function merge(LabDiscipline $source, LabDiscipline $target, User|CatalogActor $actor): int
    {
        $this->authorize($actor, 'lab_disciplines.update');
        $this->authorize($actor, 'lab_disciplines.archive');

        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_uuid' => 'Choisissez une autre discipline.']);
        }

        if ($target->trashed()) {
            throw ValidationException::withMessages(['target_uuid' => 'La discipline choisie est archivée : restaurez-la d’abord.']);
        }

        return DB::transaction(function () use ($source, $target, $actor): int {
            $moved = AnalysisCatalog::withTrashed()
                ->where('lab_discipline_id', $source->id)
                ->update(['lab_discipline_id' => $target->id, 'exam_category' => $target->name]);

            $source->deleted_by = $this->userId($actor);
            $source->delete_reason = "Fusionnée avec « {$target->name} ».";
            $source->delete();

            $this->auditor->record(
                'lab_discipline.merge',
                entity: $target,
                newValues: ['discipline' => $target->name, 'analyses' => $moved],
                oldValues: ['discipline' => $source->name],
                module: 'laboratory',
            );

            return $moved;
        });
    }

    private function name(mixed $name, string $errorKey = 'name'): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages([$errorKey => 'Indiquez le nom de la discipline (120 caractères au plus).']);
        }

        return $name;
    }

    private function ensureUnique(string $name, ?LabDiscipline $ignore = null): void
    {
        $duplicate = LabDiscipline::withTrashed()
            ->where('normalized_name', LabDiscipline::normalize($name))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['name' => $duplicate->trashed()
                ? "La discipline « {$duplicate->name} » existe, archivée : restaurez-la plutôt que de la recréer."
                : "La discipline « {$duplicate->name} » existe déjà."]);
        }
    }

    private function userId(User|CatalogActor $actor): ?int
    {
        return $actor instanceof CatalogActor ? $actor->localUserId() : $actor->getKey();
    }

    private function authorize(User|CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException("Cette action demande le droit « {$permission} ».");
        }
    }
}
