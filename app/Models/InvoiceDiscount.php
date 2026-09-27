<?php

namespace App\Models;

use App\Enums\DiscountSource;
use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-192 — la remise appliquée à une facture : ce qui a été retenu, sur quelle
 * part patient, pour quel montant, par qui et quand. Un instantané : modifier
 * ensuite la règle VIP, le coupon ou la remise du patient ne réécrit jamais une
 * facture. Une remise retirée reste là, datée et signée (CDC §34.2 règle 7).
 */
#[Fillable([
    'invoice_id', 'source', 'label', 'discount_type', 'discount_value', 'base_amount', 'amount',
    'discount_coupon_id', 'patient_discount_id', 'applied_by', 'applied_at',
    'removed_at', 'removed_by', 'remove_reason', 'active_key',
])]
class InvoiceDiscount extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'source' => DiscountSource::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(DiscountCoupon::class, 'discount_coupon_id');
    }

    public function patientDiscount(): BelongsTo
    {
        return $this->belongsTo(PatientDiscount::class);
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
