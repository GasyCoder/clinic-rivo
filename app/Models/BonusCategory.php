<?php

namespace App\Models;

use App\Enums\BonusMeasure;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ADR-212 — une catégorie de bonus : ce qu'elle compte chaque mois
 * (BonusMeasure), à partir de combien de patients (`threshold`), pour quel
 * montant fixe (`amount`), et les membres du personnel qu'elle concerne.
 *
 * Rien n'est supprimé : une catégorie s'archive avec un motif et se restaure.
 * Une catégorie qui a donné un bonus ne se supprime jamais définitivement.
 */
#[Fillable(['name', 'measure', 'threshold', 'amount', 'description', 'active', 'created_by', 'external_created_by_uuid', 'external_created_by_name'])]
class BonusCategory extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->name = Str::squish((string) $category->name);
            $category->normalized_name = self::normalize($category->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    protected function casts(): array
    {
        return [
            'measure' => BonusMeasure::class,
            'threshold' => 'integer',
            'amount' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'bonus_category_employees')->withTimestamps();
    }

    public function awards(): HasMany
    {
        return $this->hasMany(BonusAward::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->awards()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
