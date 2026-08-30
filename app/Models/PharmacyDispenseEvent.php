<?php

namespace App\Models;

use App\Models\Builders\ImmutableBuilder;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['pharmacy_dispense_id', 'delivery_number', 'notes', 'dispensed_at', 'dispensed_by'])]
class PharmacyDispenseEvent extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un bon de sortie validé ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un bon de sortie validé ne peut pas être supprimé.'));
    }

    protected function casts(): array
    {
        return ['dispensed_at' => 'datetime'];
    }

    public function dispense(): BelongsTo
    {
        return $this->belongsTo(PharmacyDispense::class, 'pharmacy_dispense_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PharmacyDispenseAllocation::class);
    }

    public function dispenser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
