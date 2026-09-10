<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'active'])]
class CashRegister extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $register): void {
            $register->name = Str::squish($register->name);
            $register->normalized_name = self::normalize($register->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    /**
     * Tenders this desk accepts. Empty means no restriction: every active
     * tender of the site is accepted (see the pivot migration).
     */
    public function acceptedPaymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'cash_register_payment_method')
            ->withTimestamps();
    }

    /** @return array<int, int> */
    public function acceptedPaymentMethodIds(): array
    {
        return $this->acceptedPaymentMethods()->pluck('payment_methods.id')->all();
    }

    public function accepts(PaymentMethod $method): bool
    {
        $accepted = $this->acceptedPaymentMethodIds();

        return $accepted === [] || in_array($method->id, $accepted, true);
    }

    /**
     * hasOne() already scopes to this register's own sessions (cash_register_id),
     * and within that scope at most one row can ever carry a non-null
     * active_key — its value is always exactly 'REGISTER_'.$this->id by
     * construction (CashSession::activeKeyFor()), so checking non-null here
     * is equivalent without referencing $this. That distinction matters:
     * eager loading (with()/loadMissing()) builds this relation once from an
     * id-less prototype model, so a where() keyed on $this->id would silently
     * resolve to no active session for every register in the batch.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(CashSession::class)->whereNotNull('active_key');
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->sessions()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'cash';
    }
}
