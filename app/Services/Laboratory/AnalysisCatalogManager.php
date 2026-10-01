<?php

namespace App\Services\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\LabEntryMode;
use App\Models\AnalysisCatalog;
use App\Models\LabDiscipline;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Support\Laboratory\LabCriticalRange;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisCatalogManager
{
    /** Ce que le formulaire envoie pour la discipline, jamais écrit tel quel (ADR-238). */
    private const DISCIPLINE_INPUT = ['lab_discipline_uuid', 'new_discipline_name', 'exam_category'];

    public function __construct(private readonly LabDisciplineManager $disciplines) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($data, $actor): AnalysisCatalog {
            $catalogActor = $this->actor($actor);
            [$catalogItem, $parent] = $this->relations($data);
            $data = $this->criticalRanges($data);
            $this->assertEntryMode($data);
            $disciplineId = $this->disciplineId($data, $parent, null, $catalogActor);

            return AnalysisCatalog::query()->create([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid', ...self::DISCIPLINE_INPUT]),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'lab_discipline_id' => $disciplineId,
                'created_by' => $catalogActor->localUserId(),
                'updated_by' => $catalogActor->localUserId(),
                ...$catalogActor->externalAttribution('created'),
                ...$catalogActor->externalAttribution('updated'),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(AnalysisCatalog $analysis, array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($analysis, $data, $actor): AnalysisCatalog {
            $catalogActor = $this->actor($actor);
            $locked = AnalysisCatalog::query()->lockForUpdate()->findOrFail($analysis->getKey());
            [$catalogItem, $parent] = $this->relations($data, $locked);
            $data = $this->criticalRanges($data);
            $this->assertEntryMode([...$locked->only(['result_type', 'entry_mode']), ...$data]);
            $disciplineId = $this->disciplineId($data, $parent, $locked, $catalogActor);

            $locked->update([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid', ...self::DISCIPLINE_INPUT]),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'lab_discipline_id' => $disciplineId,
                'updated_by' => $catalogActor->localUserId(),
                ...$catalogActor->externalAttribution('updated'),
            ]);

            if ($locked->wasChanged('catalog_item_id')) {
                $this->synchronizeDescendantCatalogItem($locked, $catalogItem, $catalogActor);
            }

            if ($locked->wasChanged('lab_discipline_id')) {
                $this->synchronizeDescendantDiscipline($locked);
            }

            return $locked->fresh(['catalogItem', 'parent']);
        });
    }

    /**
     * Saves a group (or standalone analysis) together with its inline
     * sub-analyses in one transaction — additive to create()/update(),
     * which stay the path for a sub-analysis that itself needs its own
     * sub-analyses (depth > 1), via the normal parent picker.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveWithChildren(?AnalysisCatalog $existing, array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($existing, $data, $actor): AnalysisCatalog {
            $children = $data['children'] ?? [];
            $rootData = Arr::except($data, ['children']);

            $root = $existing
                ? $this->update($existing, $rootData, $actor)
                : $this->create($rootData, $actor);

            $this->syncChildren($root, $children, $actor);

            return $root->fresh(['catalogItem', 'parent', 'children']);
        });
    }

    /**
     * Recursive on purpose: a sub-analysis edited inline can itself be a
     * group with its own sub-analyses (grandchildren from $parent's point of
     * view). The form only ever sends two levels deep (validated in
     * StoreAnalysisCatalogRequest/the API rules), but nothing here assumes
     * that depth — a request simply won't carry a third level to recurse
     * into, since Laravel drops any input key without a matching rule.
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function syncChildren(AnalysisCatalog $parent, array $children, User|CatalogActor $actor): void
    {
        $keptIds = [];

        foreach (array_values($children) as $index => $childData) {
            $childUuid = $childData['uuid'] ?? null;
            $existingChild = $childUuid
                ? AnalysisCatalog::query()->where('uuid', $childUuid)->where('parent_id', $parent->getKey())->first()
                : null;

            $codeOwner = AnalysisCatalog::withTrashed()->where('code', $childData['code'])->first();
            if ($codeOwner && (! $existingChild || $codeOwner->isNot($existingChild))) {
                throw ValidationException::withMessages([
                    "children.{$index}.code" => "Le code « {$childData['code']} » est déjà utilisé.",
                ]);
            }

            $grandchildren = $childData['children'] ?? [];
            $childData = $this->criticalRanges($childData, "children.{$index}.critical_ranges");
            $this->assertEntryMode([...($existingChild?->only(['result_type', 'entry_mode']) ?? []), ...$childData], "children.{$index}.entry_mode");
            $payload = [
                ...Arr::except($childData, ['uuid', 'children']),
                'catalog_item_uuid' => $parent->catalogItem->uuid,
                'parent_uuid' => $parent->uuid,
                'display_order' => $childData['display_order'] ?? ($index + 1),
                'is_active' => $childData['is_active'] ?? true,
            ];

            $child = $existingChild
                ? $this->update($existingChild, $payload, $actor)
                : $this->create($payload, $actor);

            $keptIds[] = $child->getKey();

            if ($child->level === AnalysisCatalog::CONTAINER_LEVEL) {
                $this->syncChildren($child, $grandchildren, $actor);
            }
        }

        // A sub-analysis removed from the inline editor is deactivated,
        // never deleted — matches ADR-010: reference data is archived, not
        // destroyed.
        $parent->children()
            ->whereNotIn('id', $keptIds ?: [0])
            ->where('is_active', true)
            ->get()
            ->each(fn (AnalysisCatalog $orphan) => $this->setActive($orphan, false, $actor));
    }

    public function setActive(AnalysisCatalog $analysis, bool $active, User|CatalogActor $actor): AnalysisCatalog
    {
        $catalogActor = $this->actor($actor);
        $analysis->update([
            'is_active' => $active,
            'updated_by' => $catalogActor->localUserId(),
            ...$catalogActor->externalAttribution('updated'),
        ]);

        return $analysis->fresh();
    }

    /**
     * ADR-238 — un mode de saisie fixé doit convenir au type de résultat : la
     * fiche ne propose que ceux-là, le serveur refuse les autres. Laissé vide
     * (« Automatique »), il se déduit toujours d'un mode qui convient.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertEntryMode(array $data, string $errorKey = 'entry_mode'): void
    {
        $mode = LabEntryMode::tryFrom((string) ($data['entry_mode'] ?? ''));

        if ($mode !== null && ! $mode->fitsResultType($data['result_type'] ?? null)) {
            $type = self::RESULT_TYPE_LABELS[$data['result_type'] ?? ''] ?? (string) ($data['result_type'] ?? '');

            throw ValidationException::withMessages([
                $errorKey => "La saisie « {$mode->label()} » ne convient pas à un résultat « {$type} » : choisissez-en une de la liste, ou « Automatique ».",
            ]);
        }
    }

    private const RESULT_TYPE_LABELS = ['NUMERIC' => 'Numérique', 'TEXT' => 'Texte', 'CHOICE' => 'Choix', 'BOOLEAN' => 'Oui / Non'];

    /**
     * ADR-238 — la discipline d'une analyse. Une analyse dans un groupe prend
     * celle de son groupe ; sinon celle choisie dans la liste, ou une nouvelle
     * nommée dans la fiche. Une clé absente laisse la discipline en place.
     *
     * @param  array<string, mixed>  $data
     */
    private function disciplineId(array $data, ?AnalysisCatalog $parent, ?AnalysisCatalog $current, CatalogActor $actor): ?int
    {
        if ($parent !== null) {
            return $parent->lab_discipline_id;
        }

        if (filled($data['new_discipline_name'] ?? null)) {
            return $this->disciplines->findOrCreate((string) $data['new_discipline_name'], $actor)->id;
        }

        if (! array_key_exists('lab_discipline_uuid', $data)) {
            return $current?->lab_discipline_id;
        }

        if (blank($data['lab_discipline_uuid'])) {
            return null;
        }

        $discipline = LabDiscipline::withTrashed()->where('uuid', $data['lab_discipline_uuid'])->first();

        // Une discipline archivée reste acceptée pour l'analyse qui la porte déjà.
        if ($discipline === null || ($discipline->trashed() && $discipline->id !== $current?->lab_discipline_id)) {
            throw ValidationException::withMessages(['lab_discipline_uuid' => 'Choisissez une discipline de la liste.']);
        }

        return $discipline->id;
    }

    private function synchronizeDescendantDiscipline(AnalysisCatalog $group): void
    {
        $pending = $group->children()->get();

        while ($pending->isNotEmpty()) {
            $next = collect();

            foreach ($pending as $descendant) {
                $descendant->update(['lab_discipline_id' => $group->lab_discipline_id]);
                $next->push(...$descendant->children()->get());
            }

            $pending = $next;
        }
    }

    private function actor(User|CatalogActor $actor): CatalogActor
    {
        return $actor instanceof User ? CatalogActor::fromUser($actor) : $actor;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CatalogItem, ?AnalysisCatalog}
     */
    /**
     * ADR-214 — les bornes critiques, relues en nombres (« 2,5 » vaut 2.5), la
     * basse sous la haute ; une clé absente laisse les bornes en place.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function criticalRanges(array $data, string $errorKey = 'critical_ranges'): array
    {
        if (array_key_exists('critical_ranges', $data)) {
            $data['critical_ranges'] = LabCriticalRange::normalize(is_array($data['critical_ranges']) ? $data['critical_ranges'] : null, $errorKey);
        }

        return $data;
    }

    private function relations(array $data, ?AnalysisCatalog $current = null): array
    {
        $catalogItem = CatalogItem::query()
            ->where('uuid', $data['catalog_item_uuid'])
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->firstOrFail();

        $parent = filled($data['parent_uuid'] ?? null)
            ? AnalysisCatalog::query()->where('uuid', $data['parent_uuid'])->firstOrFail()
            : null;

        if ($parent && $current && $parent->is($current)) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une analyse ne peut pas être son propre parent.']);
        }

        if ($parent && ! $parent->acceptsChildren()) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Le parent sélectionné doit être un groupe d’analyses.',
            ]);
        }

        if ($parent && $current && $this->isDescendantOf($parent, $current)) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Ce groupe appartient déjà à cette arborescence. Ce choix créerait une boucle.',
            ]);
        }

        if ($parent && $parent->catalog_item_id !== $catalogItem->getKey()) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Le parent doit appartenir à la même prestation Laboratoire.',
            ]);
        }

        $level = $data['level'] ?? null;

        if ($level === AnalysisCatalog::TERMINAL_LEVEL && ! $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une sous-analyse doit posséder un parent.']);
        }

        if ($level === AnalysisCatalog::STANDALONE_LEVEL && $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une analyse autonome ne peut pas posséder de parent.']);
        }

        if ($current && $current->children()->exists() && $level !== AnalysisCatalog::CONTAINER_LEVEL) {
            throw ValidationException::withMessages([
                'level' => 'Ce groupe contient des éléments et doit rester de niveau Groupe.',
            ]);
        }

        return [$catalogItem, $parent];
    }

    private function isDescendantOf(AnalysisCatalog $candidate, AnalysisCatalog $ancestor): bool
    {
        $visited = [];
        $current = $candidate;

        while ($current->parent_id !== null) {
            if (isset($visited[$current->getKey()])) {
                throw ValidationException::withMessages([
                    'parent_uuid' => 'La hiérarchie existante contient déjà une relation cyclique.',
                ]);
            }

            $visited[$current->getKey()] = true;

            if ($current->parent_id === $ancestor->getKey()) {
                return true;
            }

            $current = AnalysisCatalog::query()->find($current->parent_id);
            if (! $current) {
                return false;
            }
        }

        return false;
    }

    private function synchronizeDescendantCatalogItem(
        AnalysisCatalog $group,
        CatalogItem $catalogItem,
        CatalogActor $actor,
    ): void {
        $pending = $group->children()->get();

        while ($pending->isNotEmpty()) {
            $next = collect();

            foreach ($pending as $descendant) {
                $descendant->update([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('updated'),
                ]);

                $next->push(...$descendant->children()->get());
            }

            $pending = $next;
        }
    }
}
