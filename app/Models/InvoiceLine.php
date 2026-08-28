<?php

namespace App\Models;

use App\Enums\StaffCoveragePolicy;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'invoice_id', 'billable_item_id', 'description', 'quantity', 'unit_price', 'line_total',
    'gross_line_total', 'staff_coverage_policy', 'coverage_rate', 'coverage_amount',
    'staff_covered_amount', 'staff_block_credit_used',
    'source_type', 'source_uuid', 'status', 'created_by',
])]
class InvoiceLine extends Model
{
    use Auditable, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'gross_line_total' => 'decimal:2',
            'staff_coverage_policy' => StaffCoveragePolicy::class,
            'coverage_rate' => 'decimal:2',
            'coverage_amount' => 'decimal:2',
            'staff_covered_amount' => 'decimal:2',
            'staff_block_credit_used' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }
}
