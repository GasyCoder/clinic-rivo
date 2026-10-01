<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ADR-238 — une discipline du laboratoire (Hématologie, Biochimie…) : elle
 * range la feuille de paillasse et la section du compte rendu, dans son ordre.
 * Un référentiel du site, jamais un texte libre : deux orthographes ne font
 * plus deux feuilles. Rien n'est supprimé : elle s'archive avec un motif, se
 * fusionne avec une autre, se restaure.
 */
#[Fillable(['name', 'display_order', 'is_active', 'created_by', 'updated_by'])]
class LabDiscipline extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $discipline): void {
            $discipline->name = Str::squish((string) $discipline->name);
            $discipline->normalized_name = self::normalize($discipline->name);
        });
    }

    /** « HEMATOLOGIE », « Hématologie » et « hématologie  » se lisent pareil. */
    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'display_order' => 'integer'];
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(AnalysisCatalog::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return AnalysisCatalog::withTrashed()->where('lab_discipline_id', $this->id)->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
