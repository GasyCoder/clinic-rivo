<?php

namespace App\Services\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalysisCatalogImportService
{
    public const HEADERS = [
        'Code prestation', 'Code analyse', 'Niveau', 'Code parent', 'Désignation',
        'Description', 'Type résultat', 'Référence générale', 'Référence homme',
        'Référence femme', 'Référence enfant garçon', 'Référence enfant fille',
        'Unité', 'Valeurs prédéfinies', 'Ordre', 'Statut',
    ];

    public function __construct(private readonly AnalysisCatalogManager $manager) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int}
     */
    public function import(array $rows, User|CatalogActor $actor): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune ligne à importer.']);
        }

        if (count($rows) > 1000) {
            throw ValidationException::withMessages(['file' => 'Un import est limité à 1 000 lignes.']);
        }

        $normalized = collect($rows)->map(fn (array $row, int $index) => $this->normalizeRow($row, $index + 2));
        $duplicate = $normalized->pluck('code')->duplicates()->first();
        if ($duplicate) {
            throw ValidationException::withMessages(['file' => "Le code {$duplicate} apparaît plusieurs fois dans le fichier."]);
        }

        $catalogItems = CatalogItem::query()
            ->whereIn('code', $normalized->pluck('catalog_item_code')->unique())
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->get()
            ->keyBy('code');

        $missingCatalog = $normalized->pluck('catalog_item_code')->unique()->diff($catalogItems->keys())->first();
        if ($missingCatalog) {
            throw ValidationException::withMessages([
                'file' => "La prestation Laboratoire {$missingCatalog} n’existe pas dans catalog_items.",
            ]);
        }

        return DB::transaction(function () use ($normalized, $catalogItems, $actor): array {
            $counts = ['created' => 0, 'updated' => 0];

            $pending = $normalized->keyBy('code');
            while ($pending->isNotEmpty()) {
                $processed = collect();

                foreach ($pending as $row) {
                    if ($row['parent_code'] !== '' && $pending->has($row['parent_code'])) {
                        continue;
                    }

                    $this->persistRow($row, $catalogItems, $actor, $counts);
                    $processed->push($row['code']);
                }

                if ($processed->isEmpty()) {
                    $row = $pending->first();
                    throw ValidationException::withMessages([
                        'file' => "Ligne {$row['row_number']} : la hiérarchie contient un cycle ou un parent impossible à résoudre autour de {$row['code']}.",
                    ]);
                }

                $pending = $pending->except($processed->all());
            }

            return $counts;
        });
    }

    /** @return array<string, mixed> */
    private function normalizeRow(array $row, int $rowNumber): array
    {
        $values = [
            'row_number' => $rowNumber,
            'catalog_item_code' => mb_strtoupper(trim((string) ($row['code_prestation'] ?? ''))),
            'code' => mb_strtoupper(trim((string) ($row['code_analyse'] ?? ''))),
            'level' => mb_strtoupper(trim((string) ($row['niveau'] ?? 'NORMAL'))),
            'parent_code' => mb_strtoupper(trim((string) ($row['code_parent'] ?? ''))),
            'designation' => trim((string) ($row['designation'] ?? '')),
            'description' => $this->nullable($row['description'] ?? null),
            'result_type' => mb_strtoupper(trim((string) ($row['type_resultat'] ?? 'TEXT'))),
            'reference_general' => $this->nullable($row['reference_generale'] ?? null),
            'reference_male' => $this->nullable($row['reference_homme'] ?? null),
            'reference_female' => $this->nullable($row['reference_femme'] ?? null),
            'reference_child_male' => $this->nullable($row['reference_enfant_garcon'] ?? null),
            'reference_child_female' => $this->nullable($row['reference_enfant_fille'] ?? null),
            'unit' => $this->nullable($row['unite'] ?? null),
            'predefined_values' => collect(explode('|', (string) ($row['valeurs_predefinies'] ?? '')))
                ->map(fn ($value) => trim($value))->filter()->unique()->values()->all(),
            'display_order' => (int) ($row['ordre'] ?? 0),
            'is_active' => ! in_array(mb_strtoupper(trim((string) ($row['statut'] ?? 'ACTIVE'))), ['INACTIVE', 'INACTIF', '0'], true),
        ];

        $validator = Validator::make($values, [
            'catalog_item_code' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:80'],
            'level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'parent_code' => [
                Rule::requiredIf($values['level'] === AnalysisCatalog::TERMINAL_LEVEL),
                Rule::prohibitedIf($values['level'] === AnalysisCatalog::STANDALONE_LEVEL),
                'nullable', 'string', 'max:80',
            ],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'reference_general' => ['nullable', 'string', 'max:255'],
            'reference_male' => ['nullable', 'string', 'max:255'],
            'reference_female' => ['nullable', 'string', 'max:255'],
            'reference_child_male' => ['nullable', 'string', 'max:255'],
            'reference_child_female' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:60'],
            'predefined_values' => ['array', 'max:30'],
            'predefined_values.*' => ['string', 'max:100'],
            'display_order' => ['integer', 'min:0', 'max:10000'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'file' => "Ligne {$rowNumber} : ".$validator->errors()->first(),
            ]);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, CatalogItem>  $catalogItems
     * @param  array{created: int, updated: int}  $counts
     */
    private function persistRow(array $row, $catalogItems, User|CatalogActor $actor, array &$counts): void
    {
        $catalogItem = $catalogItems->get($row['catalog_item_code']);
        $parent = $row['parent_code'] !== ''
            ? AnalysisCatalog::query()->where('code', $row['parent_code'])->first()
            : null;

        if ($row['parent_code'] !== '' && ! $parent) {
            throw ValidationException::withMessages([
                'file' => "Ligne {$row['row_number']} : le parent {$row['parent_code']} est introuvable.",
            ]);
        }

        $payload = [
            'catalog_item_uuid' => $catalogItem->uuid,
            'parent_uuid' => $parent?->uuid,
            'code' => $row['code'],
            'level' => $row['level'],
            'designation' => $row['designation'],
            'description' => $row['description'],
            'result_type' => $row['result_type'],
            'reference_general' => $row['reference_general'],
            'reference_male' => $row['reference_male'],
            'reference_female' => $row['reference_female'],
            'reference_child_male' => $row['reference_child_male'],
            'reference_child_female' => $row['reference_child_female'],
            'unit' => $row['unit'],
            'predefined_values' => $row['predefined_values'],
            'display_order' => $row['display_order'],
            'is_active' => $row['is_active'],
        ];

        $existing = AnalysisCatalog::withTrashed()->where('code', $row['code'])->first();
        if ($existing?->trashed()) {
            throw ValidationException::withMessages([
                'file' => "Ligne {$row['row_number']} : {$row['code']} est archivée et doit être restaurée avant import.",
            ]);
        }

        if ($existing) {
            $this->manager->update($existing, $payload, $actor);
            $counts['updated']++;

            return;
        }

        $this->manager->create($payload, $actor);
        $counts['created']++;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
