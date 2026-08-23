<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['label', 'active'])]
class AddressEntry extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $entry->label = Str::squish($entry->label);
            $entry->normalized_label = self::normalize($entry->label);
        });
    }

    public static function normalize(string $label): string
    {
        return Str::lower(Str::ascii(Str::squish($label)));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function isForceDeleteProtected(): bool
    {
        // The foreign keys also protect archived patients/employees. Include
        // them here so force-delete returns the domain exception instead of
        // leaking a raw database constraint error.
        return $this->patients()->withTrashed()->exists()
            || $this->employees()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
