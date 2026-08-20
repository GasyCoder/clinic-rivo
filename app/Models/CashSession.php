<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'session_number', 'active_key', 'status', 'opening_amount',
    'expected_closing_amount', 'actual_closing_amount', 'variance_amount',
    'opened_by', 'closed_by', 'opened_at', 'closed_at', 'notes',
])]
class CashSession extends Model
{
    use HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_amount' => 'decimal:2',
            'expected_closing_amount' => 'decimal:2',
            'actual_closing_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
