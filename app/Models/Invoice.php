<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'patient_id', 'episode_id', 'invoice_number', 'status', 'currency',
    'subtotal_amount', 'discount_amount', 'total_amount', 'paid_amount',
    'balance_amount', 'created_by', 'validated_by', 'cancelled_by',
    'validated_at', 'cancelled_at', 'cancellation_reason',
])]
class Invoice extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        // The immutable invoice must keep resolving its historical patient
        // even when the administrative record is later soft-archived.
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected function auditableSkipsChange(array $changes): bool
    {
        return $this->status === InvoiceStatus::Validated
            && array_key_exists('validated_at', $changes);
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }
}
