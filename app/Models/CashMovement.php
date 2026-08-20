<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cash_session_id', 'payment_id', 'reversal_payment_id', 'payment_method_id', 'type', 'direction',
    'amount', 'affects_cash_balance', 'description', 'recorded_by', 'occurred_at',
])]
class CashMovement extends Model
{
    use HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'affects_cash_balance' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reversedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reversal_payment_id');
    }
}
