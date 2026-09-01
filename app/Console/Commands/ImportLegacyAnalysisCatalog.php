<?php

namespace App\Console\Commands;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Laboratory\AnalysisCatalogManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportLegacyAnalysisCatalog extends Command
{
    protected $signature = 'rivo:import-legacy-analyses
        {--source=ctb-cover : Base MySQL historique en lecture seule}';

    protected $description = 'Convertir le catalogue historique ctb-cover vers le référentiel RIVO courant';

    public function handle(AnalysisCatalogManager $manager): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Cette migration historique est strictement réservée aux environnements local/testing.');

            return self::FAILURE;
        }

        $source = trim((string) $this->option('source'));
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $source)) {
            $this->error('Le nom de la base source est invalide.');

            return self::FAILURE;
        }

        try {
            $legacy = $this->legacyConnection($source);
            $rows = collect($legacy->table('analyses as a')
                ->leftJoin('examens as e', 'e.id', '=', 'a.examen_id')
                ->leftJoin('types as t', 't.id', '=', 'a.type_id')
                ->select([
                    'a.*', 'e.name as legacy_exam_name', 'e.abr as legacy_exam_code',
                    't.name as legacy_type_name', 't.libelle as legacy_type_label',
                ])->orderBy('a.id')->get());

            if ($rows->isEmpty()) {
                throw new RuntimeException("La base {$source} ne contient aucune analyse.");
            }

            $actor = $this->resolveActor();
            $previous = Auth::guard()->user();
            Auth::guard()->setUser($actor);

            try {
                $result = DB::transaction(fn () => $this->importRows($rows, $actor, $manager));
            } finally {
                $previous ? Auth::guard()->setUser($previous) : Auth::guard()->forgetUser();
            }

            $this->components->success(sprintf(
                '%d analyses historiques synchronisées (%d créées, %d mises à jour), %d prestations Laboratoire racines, %d relation(s) orpheline(s) normalisée(s), %d ancienne(s) prestation(s) technique(s) archivée(s).',
                $result['created'] + $result['updated'],
                $result['created'],
                $result['updated'],
                $result['services'],
                $result['normalized_orphans'],
                $result['archived_services'],
            ));
            $this->line('<fg=gray>Les prix historiques sont conservés comme métadonnées de migration, sans devenir des tarifs actifs non validés.</>');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function legacyConnection(string $database)
    {
        $configuration = config('database.connections.mysql');
        $configuration['database'] = $database;
        config(['database.connections.rivo_legacy_analysis' => $configuration]);
        DB::purge('rivo_legacy_analysis');

        $connection = DB::connection('rivo_legacy_analysis');
        $connection->getPdo();

        return $connection;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{created: int, updated: int, services: int, normalized_orphans: int, archived_services: int}
     */
    private function importRows($rows, User $actor, AnalysisCatalogManager $manager): array
    {
        $rowsById = $rows->keyBy('id');
        $serviceByRootId = collect();
        $normalizedOrphans = 0;

        foreach ($rows as $row) {
            $hasDeclaredParent = $row->parent_id !== null;
            $hasResolvableParent = $hasDeclaredParent && $rowsById->has($row->parent_id);
            $isOrphan = $hasDeclaredParent && ! $hasResolvableParent;

            if ($hasResolvableParent) {
                continue;
            }
            if ($isOrphan) {
                $normalizedOrphans++;
            }

            $serviceByRootId->put((int) $row->id, $this->upsertService($row, $actor));
        }

        $created = 0;
        $updated = 0;
        $analysisByLegacyId = collect();
        $import = function (object $row, string $effectiveLevel) use (
            $rowsById, $serviceByRootId, $analysisByLegacyId, $actor, $manager, &$created, &$updated,
        ): void {
            $rootId = $this->rootId($row, $rowsById);
            $catalogItem = $serviceByRootId->get($rootId);
            $parent = $rowsById->has($row->parent_id)
                ? $analysisByLegacyId->get((int) $row->parent_id)
                : null;

            if (! $catalogItem || ($rowsById->has($row->parent_id) && ! $parent)) {
                throw new RuntimeException("La relation de l’analyse historique #{$row->id} ne peut pas être reconstruite.");
            }

            $payload = [
                'catalog_item_uuid' => $catalogItem->uuid,
                'parent_uuid' => $parent?->uuid,
                'code' => trim((string) $row->code),
                'level' => $effectiveLevel,
                'designation' => trim((string) ($row->designation ?: $row->code)),
                'description' => $this->nullable($row->description),
                'exam_category' => $this->nullable($row->legacy_exam_name),
                'result_type' => $this->resultType((string) $row->legacy_type_name),
                'reference_general' => $this->nullable($row->valeur_ref),
                'reference_male' => $this->nullable($row->valeur_ref_homme),
                'reference_female' => $this->nullable($row->valeur_ref_femme),
                'reference_child_male' => $this->nullable($row->valeur_ref_enfant_garcon),
                'reference_child_female' => $this->nullable($row->valeur_ref_enfant_fille),
                'unit' => $this->nullable($row->unite) ?? $this->nullable($row->suffixe),
                'predefined_values' => $this->predefinedValues($row->valeurs_predefinies),
                'display_order' => (int) ($row->ordre ?? 0),
                'is_active' => (bool) $row->status,
                'is_bold' => (bool) $row->is_bold,
                'source_system' => 'CTB_COVER',
                'source_id' => (int) $row->id,
                'source_metadata' => [
                    'exam_id' => $row->examen_id,
                    'exam_code' => $row->legacy_exam_code,
                    'exam_name' => $row->legacy_exam_name,
                    'type_id' => $row->type_id,
                    'type_name' => $row->legacy_type_name,
                    'type_label' => $row->legacy_type_label,
                    'legacy_price' => $row->prix,
                    'legacy_is_bold' => (bool) $row->is_bold,
                    'legacy_suffix' => $row->suffixe,
                    'normalized_from_orphan_child' => $row->level === 'CHILD' && $effectiveLevel === 'NORMAL',
                ],
            ];

            $analysis = AnalysisCatalog::withTrashed()
                ->where('source_system', 'CTB_COVER')->where('source_id', $row->id)
                ->first();

            if (! $analysis) {
                $collision = AnalysisCatalog::withTrashed()->where('code', $payload['code'])->first();

                // A code shared with an existing, never-before-imported analysis
                // is a genuine ambiguity, not "the same record" — silently
                // repurposing it would corrupt whatever it already represented
                // (exactly what happened to the seeded NFS/CRP/CREAT/ALAT/HDL/
                // LDL/K/ECBU groups the first time this ran). Refuse instead of
                // guessing; the operator must rename the historical code or
                // resolve the collision explicitly before retrying.
                if ($collision && $collision->source_system !== 'CTB_COVER') {
                    throw new RuntimeException(
                        "L’analyse historique #{$row->id} porte le code {$payload['code']}, déjà utilisé par ".
                        "une analyse existante ({$collision->uuid}) jamais importée depuis {$this->option('source')}. ".
                        'Renommez le code historique ou traitez cette collision avant de relancer la migration.',
                    );
                }

                $analysis = $collision;
            }

            if ($analysis?->trashed()) {
                throw new RuntimeException("L’analyse {$payload['code']} est archivée ; restaurez-la avant la migration.");
            }

            if ($analysis) {
                $analysis = $manager->update($analysis, $payload, $actor);
                $updated++;
            } else {
                $analysis = $manager->create($payload, $actor);
                $created++;
            }
            $analysisByLegacyId->put((int) $row->id, $analysis);
        };

        $roots = $rows->filter(fn (object $row): bool => ! $rowsById->has($row->parent_id));
        foreach ($roots as $row) {
            $import($row, $row->level === 'CHILD' ? 'NORMAL' : $row->level);
        }

        $pending = $rows->reject(fn (object $row) => $roots->contains('id', $row->id));
        while ($pending->isNotEmpty()) {
            $processed = collect();
            foreach ($pending as $row) {
                if (! $analysisByLegacyId->has((int) $row->parent_id)) {
                    continue;
                }
                $import($row, $row->level);
                $processed->push($row->id);
            }

            if ($processed->isEmpty()) {
                throw new RuntimeException(
                    'Une relation cyclique ou invalide empêche la reconstruction de '.($pending->count()).' analyse(s) historique(s).',
                );
            }
            $pending = $pending->reject(fn (object $row) => $processed->contains($row->id));
        }

        $archivedServices = $this->archiveUnusedGeneratedServices($serviceByRootId, $actor);

        return [
            'created' => $created,
            'updated' => $updated,
            'services' => $serviceByRootId->count(),
            'normalized_orphans' => $normalizedOrphans,
            'archived_services' => $archivedServices,
        ];
    }

    /** @param Collection<int, object> $rowsById */
    private function rootId(object $row, $rowsById): int
    {
        $current = $row;
        $visited = collect();

        while ($rowsById->has($current->parent_id)) {
            if ($visited->contains((int) $current->id)) {
                throw new RuntimeException("Une relation cyclique existe autour de l’analyse historique #{$row->id}.");
            }
            $visited->push((int) $current->id);
            $current = $rowsById->get($current->parent_id);
        }

        return (int) $current->id;
    }

    private function upsertService(object $row, User $actor): CatalogItem
    {
        $code = 'LEGACY-LAB-'.(int) $row->id;
        $item = CatalogItem::withTrashed()->where('code', $code)->first();
        if ($item?->trashed()) {
            $item->restore();
        }

        $values = [
            'name' => trim((string) ($row->designation ?: $row->code)),
            'type' => CatalogItemType::Service->value,
            'module' => CatalogModule::Laboratory->value,
            'unit' => 'analyse',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => false,
            'reception_routing_mode' => null,
            'clinician_orderable' => true,
            'description' => 'Catalogue historique '.trim((string) $row->legacy_exam_name).'. Tarif à valider avant activation financière.',
            'updated_by' => $actor->id,
        ];

        if ($item) {
            $item->update($values);

            return $item->fresh();
        }

        return CatalogItem::query()->create(['code' => $code, ...$values, 'created_by' => $actor->id]);
    }

    /** @param Collection<int, CatalogItem> $rootServices */
    private function archiveUnusedGeneratedServices($rootServices, User $actor): int
    {
        $rootIds = $rootServices->pluck('id');
        $archived = 0;

        CatalogItem::query()
            ->where('code', 'like', 'LEGACY-LAB-%')
            ->whereNotIn('id', $rootIds)
            ->whereDoesntHave('analysisDefinitions')
            ->each(function (CatalogItem $item) use ($actor, &$archived): void {
                $item->delete_reason = 'Ancienne prestation technique remplacée par la hiérarchie récursive du catalogue historique.';
                $item->updated_by = $actor->id;
                $item->save();
                $item->delete();
                $archived++;
            });

        return $archived;
    }

    private function resultType(string $legacyType): string
    {
        return match ($legacyType) {
            'DOSAGE', 'COMPTAGE', 'LEUCOCYTES' => 'NUMERIC',
            'TEST', 'SELECT', 'NEGATIF_POSITIF_1', 'NEGATIF_POSITIF_2',
            'NEGATIF_POSITIF_3', 'ABSENCE_PRESENCE_2', 'SELECT_MULTIPLE' => 'CHOICE',
            default => 'TEXT',
        };
    }

    /** @return array<int, string> */
    private function predefinedValues(mixed $value): array
    {
        if (! filled($value)) {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded)
            ? collect($decoded)->map(fn ($item) => trim((string) $item))->filter()->unique()->values()->all()
            : [];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resolveActor(): User
    {
        $identity = trim((string) config('rivo.seeders.catalog_actor'));
        $query = User::query()->where('active', true)->whereNull('deactivated_at');
        $actor = $identity !== ''
            ? $query->where(fn ($nested) => $nested->where('uuid', $identity)->orWhere('email', $identity))->first()
            : $query->orderBy('id')->first();

        if (! $actor) {
            throw new RuntimeException('Aucun compte local actif ne peut être attribué à la migration historique.');
        }

        return $actor;
    }
}
