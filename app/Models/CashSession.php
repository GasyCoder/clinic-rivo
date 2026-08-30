<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'session_number', 'active_key', 'cash_register_id', 'status', 'opening_amount',
    'expected_closing_amount', 'actual_closing_amount', 'variance_amount',
    'opened_by', 'closed_by', 'opened_at', 'closed_at', 'notes',
    'locked_by', 'external_locked_by_uuid', 'external_locked_by_name', 'locked_at', 'lock_reason',
    'unlocked_by', 'external_unlocked_by_uuid', 'external_unlocked_by_name', 'unlocked_at',
    'external_closed_by_uuid', 'external_closed_by_name', 'closing_reason',
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
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
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

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * A named register gets its own slot on the active_key unique index
     * ('REGISTER_<id>'), so two different registers never collide and can
     * each hold an open session at the same time; a site with no register
     * configured keeps the original site-wide sentinel, unchanged.
     */
    public static function activeKeyFor(?CashRegister $register): string
    {
        return $register ? 'REGISTER_'.$register->id : 'SINGLE_OPEN_CASH';
    }

    /**
     * Single source of truth for "what the drawer should physically hold
     * right now" — opening float plus every cash-affecting movement since.
     * Read by the index summary, the closing form's pre-fill, and
     * CloseCashSessionAction's own recomputation at close time.
     */
    public function computeExpectedClosingAmount(): string
    {
        $cashMinor = $this->movements()
            ->where('affects_cash_balance', true)
            ->get(['direction', 'amount'])
            ->sum(fn (CashMovement $movement) => $movement->direction === 'OUT'
                ? -Money::toMinor($movement->amount)
                : Money::toMinor($movement->amount));

        return Money::fromMinor(Money::toMinor($this->opening_amount) + $cashMinor);
    }
}
