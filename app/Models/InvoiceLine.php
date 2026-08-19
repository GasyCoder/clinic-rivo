<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'invoice_id', 'description', 'quantity', 'unit_price', 'line_total',
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
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }
}
