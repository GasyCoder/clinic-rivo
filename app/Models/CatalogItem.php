<?php

namespace App\Models;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code', 'name', 'type', 'module', 'unit', 'billable', 'stockable',
    'reception_selectable', 'reception_routing_mode', 'staff_coverage_policy', 'description',
    'care_requires_allergy_check', 'care_recommends_vitals', 'clinician_orderable',
    'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
])]
class CatalogItem extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'type' => CatalogItemType::class,
            'module' => CatalogModule::class,
            'billable' => 'boolean',
            'stockable' => 'boolean',
            'reception_selectable' => 'boolean',
            'reception_routing_mode' => ReceptionRoutingMode::class,
            'staff_coverage_policy' => StaffCoveragePolicy::class,
            'care_requires_allergy_check' => 'boolean',
            'care_recommends_vitals' => 'boolean',
            'clinician_orderable' => 'boolean',
        ];
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(CatalogTariff::class)->latest('effective_from');
    }

    public function currentTariff(): HasOne
    {
        return $this->currentTariffFor(CatalogTariffCategory::Standard);
    }

    public function currentStandardTariff(): HasOne
    {
        return $this->currentTariffFor(CatalogTariffCategory::Standard);
    }

    public function currentMutualTariff(): HasOne
    {
        return $this->currentTariffFor(CatalogTariffCategory::Mutual);
    }

    public function currentTariffFor(CatalogTariffCategory $category): HasOne
    {
        return $this->hasOne(CatalogTariff::class)
            ->where('tariff_category', $category->value)
            ->where('active_key', 'CURRENT');
    }

    public function billableItems(): HasMany
    {
        return $this->hasMany(BillableItem::class);
    }

    public function episodeServiceRequests(): HasMany
    {
        return $this->hasMany(EpisodeServiceRequest::class);
    }

    public function careOrderItems(): HasMany
    {
        return $this->hasMany(CareOrderItem::class);
    }

    public function medicine(): HasOne
    {
        return $this->hasOne(Medicine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->tariffs()->exists()
            || $this->billableItems()->exists()
            || $this->episodeServiceRequests()->exists()
            || $this->careOrderItems()->exists()
            || $this->medicine()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'catalog';
    }
}
