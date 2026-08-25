<?php

namespace App\Models;

use App\Enums\BillableItemStatus;
use App\Enums\CatalogTariffCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'episode_id', 'source_module', 'source_type', 'source_id', 'source_uuid',
    'catalog_item_id', 'catalog_tariff_id', 'tariff_category',
    'mutual_organization_uuid', 'mutual_organization_name', 'coverage_rate',
    'description', 'quantity', 'unit_price', 'total_amount', 'gross_amount',
    'coverage_amount', 'patient_amount', 'currency',
    'payment_required_before_fulfillment', 'status', 'created_by',
    'cancelled_by', 'cancelled_at', 'cancellation_reason',
])]
class BillableItem extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected $attributes = [
        'tariff_category' => CatalogTariffCategory::Standard->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => BillableItemStatus::class,
            'tariff_category' => CatalogTariffCategory::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'coverage_rate' => 'decimal:2',
            'coverage_amount' => 'decimal:2',
            'patient_amount' => 'decimal:2',
            'payment_required_before_fulfillment' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
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

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }

    protected function auditableSkipsChange(array $changes): bool
    {
        return $this->status === BillableItemStatus::Cancelled
            && array_key_exists('cancelled_at', $changes);
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }
}
