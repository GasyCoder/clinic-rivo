<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ADR-192 — un coupon de remise, propre à un site, créé depuis le portail. Son
 * code est unique pour toujours, archives comprises : un code qui a servi ne
 * désigne jamais autre chose. Il ne se supprime pas, il s'archive.
 */
#[Fillable([
    'code', 'label', 'discount_type', 'discount_value', 'valid_from', 'valid_until',
    'max_uses', 'uses_count', 'created_by', 'external_created_by_uuid', 'external_created_by_name',
    'archived_at', 'archived_by', 'external_archived_by_uuid', 'external_archived_by_name', 'archive_reason',
])]
class DiscountCoupon extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }

    /** Les codes se comparent sans espaces ni casse : « vip-2026 » est « VIP-2026 ». */
    public static function normalizeCode(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code) ?? '');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Pourquoi ce coupon ne peut pas servir aujourd'hui ; `null` s'il le peut. */
    public function unusableReason(?Carbon $on = null): ?string
    {
        $today = ($on ?? now())->toDateString();

        return match (true) {
            $this->archived_at !== null => 'Ce coupon est archivé.',
            $this->valid_from !== null && $this->valid_from->toDateString() > $today => 'Ce coupon n’est valable qu’à partir du '.$this->valid_from->format('d/m/Y').'.',
            $this->valid_until !== null && $this->valid_until->toDateString() < $today => 'Ce coupon a expiré le '.$this->valid_until->format('d/m/Y').'.',
            $this->max_uses !== null && $this->uses_count >= $this->max_uses => 'Ce coupon a déjà servi le nombre de fois prévu.',
            default => null,
        };
    }
}
