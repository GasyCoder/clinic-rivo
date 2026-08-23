<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only invalidation trace. The original diagnosis remains untouched.
 */
#[Fillable(['diagnosis_id', 'replacement_diagnosis_id', 'reason', 'cancelled_by', 'cancelled_at'])]
class DiagnosisCancellation extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('A diagnosis cancellation cannot be modified.');
        });

        static::deleting(function (): never {
            throw new \LogicException('A diagnosis cancellation cannot be deleted.');
        });
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class, 'replacement_diagnosis_id');
    }
}
