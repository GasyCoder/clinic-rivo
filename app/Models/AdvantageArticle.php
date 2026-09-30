<?php

namespace App\Models;

use App\Enums\AdvantageSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Un article d'avantage à l'acte (ECHO, ECG, CHIR…) : les actes du catalogue qu'il
 * compte, ce qu'il compte (actes réalisés ou patients référés) et son prix unitaire.
 * S'archive avec un motif, se restaure ; un avantage validé garde son prix figé.
 */
#[Fillable(['name', 'source', 'unit_price', 'description', 'active', 'created_by', 'external_created_by_uuid', 'external_created_by_name'])]
class AdvantageArticle extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            $article->name = Str::squish((string) $article->name);
            $article->normalized_name = self::normalize($article->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    protected function casts(): array
    {
        return [
            'source' => AdvantageSource::class,
            'unit_price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function catalogItems(): BelongsToMany
    {
        return $this->belongsToMany(CatalogItem::class, 'advantage_article_items');
    }
}
