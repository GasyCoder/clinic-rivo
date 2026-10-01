<?php

namespace App\Services\Catalog;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ImagingModality;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\MutualOrganization;
use App\Services\Care\CareConsumableDirectory;
use App\Support\Money;

/**
 * Le référentiel « Désignations & tarifs », lu une seule fois (ADR-044,
 * amendement du 2026-09-28 ter).
 *
 * Le site l'affiche directement (`/administration/catalog`), le portail le lit
 * par l'API du site (`/api/v1/super-admin/catalog`) : les deux reçoivent les
 * mêmes données, sérialisées ici, pour le même écran Vue. Deux lectures du même
 * référentiel finiraient par montrer deux choses différentes.
 */
class CatalogDirectory
{
    public function __construct(private readonly CareConsumableDirectory $consumables) {}

    /**
     * La liste de l'écran : les désignations, leurs compteurs, les listes de
     * choix et, pour qui les voit, les mutuelles.
     *
     * @param  array{q?: ?string, status?: ?string, type?: ?string, module?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters, bool $canViewTariffs, bool $canViewOrganizations): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? 'ALL';

        $query = CatalogItem::query()
            ->when($canViewTariffs, fn ($query) => $query->with([
                'currentStandardTariff.creator:id,name',
                'currentMutualTariff.creator:id,name',
                'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(20),
            ])->withCount('tariffs'))
            // Le lien Désignation ↔ catalogue des analyses (ADR-063) : combien de
            // lignes techniques la prestation porte, et sa discipline, lue sur
            // l'analyse racine — jamais recopiée sur la désignation.
            ->withCount('analysisDefinitions as analyses_count')
            ->addSelect(['analysis_discipline' => AnalysisCatalog::query()
                ->select('exam_category')
                ->whereColumn('analysis_catalogs.catalog_item_id', 'catalog_items.id')
                ->whereNull('analysis_catalogs.parent_id')
                ->orderBy('analysis_catalogs.display_order')
                ->orderBy('analysis_catalogs.id')
                ->limit(1)])
            ->when($status === 'ARCHIVED', fn ($query) => $query->onlyTrashed())
            ->when($status === 'ALL', fn ($query) => $query->withTrashed())
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(filled($filters['module'] ?? null), fn ($query) => $query->where('module', $filters['module']))
            ->orderBy('name');

        return [
            'items' => $query->get()->map(fn (CatalogItem $item) => $this->serializeItem($item, $canViewTariffs))->values()->all(),
            'summary' => $this->summary($canViewTariffs),
            'options' => $this->options(),
            'mutual_organizations' => $canViewOrganizations
                ? MutualOrganization::withTrashed()
                    ->withCount([
                        'coverages',
                        'coverages as active_coverages_count' => fn ($query) => $query->active(),
                    ])
                    ->orderBy('normalized_name')
                    ->get()
                    ->map(fn (MutualOrganization $organization) => $this->serializeOrganization($organization))
                    ->values()
                    ->all()
                : [],
            'mutual_organizations_summary' => $canViewOrganizations ? $this->organizationsSummary() : null,
        ];
    }

    /**
     * La fiche d'une désignation : ce qui la décrit, ses deux tarifs et leur
     * historique, son matériel habituel et, pour qui peut le régler, les
     * produits qu'on peut lui associer.
     *
     * @return array<string, mixed>
     */
    public function item(CatalogItem $item, bool $canViewTariffs, bool $canConfigureConsumables): array
    {
        $item = $this->load($item);

        return [
            'item' => $this->serializeItem($item, $canViewTariffs),
            'options' => $this->options(),
            'consumable_options' => $canConfigureConsumables ? $this->consumableOptions($item) : [],
        ];
    }

    /** Une désignation relue avec tout ce que sa fiche affiche. */
    public function load(CatalogItem $item): CatalogItem
    {
        return CatalogItem::withTrashed()
            ->with([
                'currentStandardTariff.creator:id,name',
                'currentMutualTariff.creator:id,name',
                'tariffs' => fn ($query) => $query->with('creator:id,name')->latest('effective_from')->limit(20),
                'defaultConsumables.medicine' => fn ($medicine) => $medicine->with('catalogItem:id,code,name,unit'),
            ])
            ->withCount(['tariffs', 'analysisDefinitions as analyses_count'])
            ->findOrFail($item->getKey());
    }

    /**
     * La catégorie de l'écran pour une désignation : son domaine, et pour
     * l'Imagerie sa famille réglée au catalogue (ADR-106), jamais devinée.
     *
     * @param  array<string, mixed>  $item
     */
    public static function categoryOf(array $item): ?string
    {
        $module = $item['module'] ?? null;

        if (! is_string($module) || $module === '') {
            return null;
        }

        if ($module !== CatalogModule::Imaging->value) {
            return $module;
        }

        return 'IMAGING:'.(filled($item['imaging_modality'] ?? null) ? $item['imaging_modality'] : 'UNCLASSIFIED');
    }

    /**
     * ADR-072 / ADR-142 / ADR-169 — un acte de soins, de la Maternité ou de
     * Chirurgie peut recevoir un matériel habituel. Rien n'est déduit du nom.
     */
    public static function acceptsConsumables(CatalogItem $item): bool
    {
        return $item->type === CatalogItemType::Service
            && ($item->module === CatalogModule::Care || CareConsumableDirectory::acceptsConfiguredProducts($item->module));
    }

    /**
     * Les produits qu'on peut associer à l'acte : la parapharmacie pour les
     * Soins, tout produit stockable pour la Maternité et le bloc.
     *
     * @return array<int, array<string, mixed>>
     */
    public function consumableOptions(CatalogItem $item): array
    {
        if (! self::acceptsConsumables($item)) {
            return [];
        }

        $options = CareConsumableDirectory::acceptsConfiguredProducts($item->module)
            ? $this->consumables->configurableStockable()
            : $this->consumables->selectableConsumables();

        return $options->values()->all();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function options(): array
    {
        return [
            'types' => collect(CatalogItemType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'billable' => $type->mustBeBillable(),
                'stockable' => $type->mustBeStockable(),
            ])->all(),
            'modules' => collect(CatalogModule::cases())->map(fn ($module) => [
                'value' => $module->value,
                'label' => $module->label(),
            ])->all(),
            // ADR-106 — la famille d'un examen d'imagerie, réglée au catalogue.
            'imaging_modalities' => collect(ImagingModality::cases())->map(fn ($modality) => [
                'value' => $modality->value,
                'label' => $modality->label(),
            ])->all(),
            'routing_modes' => collect(ReceptionRoutingMode::cases())->map(fn ($mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ])->all(),
            'staff_coverage_policies' => collect(StaffCoveragePolicy::cases())->map(fn ($policy) => [
                'value' => $policy->value,
                'label' => $policy->label(),
            ])->all(),
            'tariff_categories' => collect(CatalogTariffCategory::cases())->map(fn ($category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ])->all(),
        ];
    }

    /** @return array<string, int|null> */
    public function summary(bool $canViewTariffs): array
    {
        return [
            'active' => CatalogItem::query()->count(),
            'archived' => CatalogItem::onlyTrashed()->count(),
            'billable' => CatalogItem::query()->where('billable', true)->count(),
            'without_standard_tariff' => $canViewTariffs
                ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentStandardTariff')->count()
                : null,
            'without_mutual_tariff' => $canViewTariffs
                ? CatalogItem::query()->where('billable', true)->whereDoesntHave('currentMutualTariff')->count()
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeItem(CatalogItem $item, bool $canViewTariffs): array
    {
        $standard = $canViewTariffs ? $item->currentStandardTariff : null;
        $mutual = $canViewTariffs ? $item->currentMutualTariff : null;

        return [
            'uuid' => $item->uuid,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'module' => $item->module->value,
            'module_label' => $item->module->label(),
            'imaging_modality' => $item->imaging_modality?->value,
            'imaging_modality_label' => $item->imaging_modality?->label(),
            'analyses_count' => (int) ($item->analyses_count ?? 0),
            'analysis_discipline' => filled($item->analysis_discipline ?? null) ? trim((string) $item->analysis_discipline) : null,
            'unit' => $item->unit,
            'billable' => $item->billable,
            'stockable' => $item->stockable,
            'reception_selectable' => $item->reception_selectable,
            'reception_routing_mode' => $item->reception_routing_mode?->value,
            'reception_routing_label' => $item->reception_routing_mode?->label(),
            'staff_coverage_policy' => $item->staff_coverage_policy->value,
            'staff_coverage_policy_label' => $item->staff_coverage_policy->label(),
            'care_requires_allergy_check' => $item->care_requires_allergy_check,
            'care_recommends_vitals' => $item->care_recommends_vitals,
            // ADR-055 — un acte de soins que le médecin peut demander aux Soins.
            'clinician_orderable' => (bool) $item->clinician_orderable,
            'accepts_consumables' => self::acceptsConsumables($item),
            // ADR-072 — le matériel habituel : servi seulement quand la fiche le
            // charge ; vide tant que personne ne l'a réglé, jamais déduit du nom.
            'default_consumables' => $item->relationLoaded('defaultConsumables')
                ? $item->defaultConsumables
                    ->filter(fn ($row) => $row->medicine && $row->medicine->catalogItem)
                    ->map(fn ($row) => [
                        'medicine_uuid' => $row->medicine->uuid,
                        'code' => $row->medicine->catalogItem->code,
                        'name' => $row->medicine->catalogItem->name,
                        'unit' => $row->medicine->catalogItem->unit,
                        'default_quantity' => $row->default_quantity,
                    ])->values()->all()
                : [],
            'description' => $item->description,
            'archived' => $item->trashed(),
            'archived_at' => $item->deleted_at?->toIso8601String(),
            'archive_reason' => $item->delete_reason,
            'current_standard_tariff' => $standard ? $this->serializeTariff($standard) : null,
            'current_mutual_tariff' => $mutual ? $this->serializeTariff($mutual) : null,
            'tariffs_count' => $canViewTariffs ? $item->tariffs_count : null,
            'tariffs' => $canViewTariffs
                ? $item->tariffs->map(fn (CatalogTariff $tariff) => $this->serializeTariff($tariff))->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTariff(CatalogTariff $tariff): array
    {
        return [
            'uuid' => $tariff->uuid,
            'tariff_category' => $tariff->tariff_category->value,
            'tariff_category_label' => $tariff->tariff_category->label(),
            'amount' => $tariff->amount,
            'currency' => $tariff->currency,
            'effective_from' => $tariff->effective_from?->toIso8601String(),
            'effective_until' => $tariff->effective_until?->toIso8601String(),
            'change_reason' => $tariff->change_reason,
            'current' => $tariff->isCurrent(),
            'creator' => $tariff->creator?->name ?? $tariff->external_created_by_name,
        ];
    }

    /** @return array<string, int> */
    private function organizationsSummary(): array
    {
        return [
            'active' => MutualOrganization::query()->count(),
            'archived' => MutualOrganization::onlyTrashed()->count(),
            'active_coverages' => (int) MutualOrganization::query()
                ->withCount(['coverages as active_coverages_count' => fn ($query) => $query->active()])
                ->get()
                ->sum('active_coverages_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOrganization(MutualOrganization $organization): array
    {
        return [
            'uuid' => $organization->uuid,
            'name' => $organization->name,
            'coverage_rate' => $organization->coverage_rate,
            'patient_rate' => Money::fromMinor(10_000 - Money::toMinor($organization->coverage_rate)),
            'active' => ! $organization->trashed() && $organization->active,
            'coverages_count' => (int) ($organization->coverages_count ?? 0),
            'active_coverages_count' => (int) ($organization->active_coverages_count ?? 0),
            'archived_at' => $organization->deleted_at?->toIso8601String(),
            'archive_reason' => $organization->delete_reason,
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }
}
