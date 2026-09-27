<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ADR-192 — un coupon de remise, propre à un site, créé depuis le portail. Son
 * code est unique pour toujours, archives comprises : un code qui a servi ne
 * désigne jamais autre chose. Il s'archive ; seul un coupon archivé qui n'a
 * jamais servi se supprime définitivement (`deletionBlocker()`) — rien ne le cite.
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

    /** Les remises posées avec ce coupon, retirées comprises : l'historique des factures qui le citent. */
    public function invoiceDiscounts(): HasMany
    {
        return $this->hasMany(InvoiceDiscount::class);
    }

    /**
     * Pourquoi ce coupon ne peut pas être supprimé définitivement ; `null` s'il le
     * peut. Il faut qu'il soit archivé et qu'aucune facture ne le cite — même une
     * remise retirée depuis : la facture garde la trace de ce qui a été posé.
     *
     * `invoice_discounts_count` est lu s'il a été chargé (`withCount`), sinon compté.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->archived_at === null) {
            return 'Archivez d’abord ce coupon.';
        }

        $uses = max((int) $this->uses_count, (int) ($this->invoice_discounts_count ?? $this->invoiceDiscounts()->count()));

        return $uses > 0
            ? "Ce coupon a servi sur {$uses} facture".($uses > 1 ? 's' : '').' : il reste archivé, pour l’historique.'
            : null;
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
