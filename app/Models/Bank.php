<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use App\Support\Hr\BankName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-221 — une banque du référentiel du site (BOA, BNI, BMOI…).
 *
 * Le code et le nom se normalisent à l'écriture : deux saisies d'une même
 * banque se reconnaissent (BankName). Une banque portée par une fiche ne se
 * supprime jamais : elle s'archive avec un motif, et la fiche la garde.
 */
#[Fillable(['code', 'name', 'bank_code', 'swift_code', 'phone', 'address', 'notes', 'active', 'position'])]
class Bank extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $bank): void {
            $bank->code = BankName::code($bank->code);
            $bank->name = BankName::clean($bank->name);
            $bank->normalized_name = BankName::normalize($bank->name);
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Proposée dans les fiches : active et non archivée. */
    public function isAvailable(): bool
    {
        return $this->active && ! $this->trashed();
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->employees()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
