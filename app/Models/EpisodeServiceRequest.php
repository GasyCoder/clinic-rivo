<?php

namespace App\Models;

use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable arrival snapshot of a known clinical service request.
 *
 * It is intentionally separate from invoices: an urgent patient's clinical
 * destination must survive a later billing/cash failure, and changing the
 * catalog route must never rewrite an existing episode's plan.
 */
#[Fillable([
    'episode_id', 'catalog_item_id', 'catalog_tariff_id', 'tariff_category',
    'catalog_item_uuid', 'catalog_code',
    'designation', 'module', 'routing_mode', 'unit', 'unit_price',
    'currency', 'quantity', 'created_by',
])]
class EpisodeServiceRequest extends Model
{
    use Auditable, HasUuid;

    protected $attributes = [
        'tariff_category' => CatalogTariffCategory::Standard->value,
    ];

    protected function casts(): array
    {
        return [
            'module' => CatalogModule::class,
            'routing_mode' => ReceptionRoutingMode::class,
            'tariff_category' => CatalogTariffCategory::class,
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function catalogTariff(): BelongsTo
    {
        return $this->belongsTo(CatalogTariff::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }
}
