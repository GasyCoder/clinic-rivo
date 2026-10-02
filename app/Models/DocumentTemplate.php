<?php

namespace App\Models;

use App\Enums\DocumentDataContext;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lineage_id', 'document_type', 'data_context', 'applies_to', 'name', 'description', 'content', 'content_html', 'active',
    'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
])]
class DocumentTemplate extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'data_context' => DocumentDataContext::class,
            'applies_to' => 'array',
            'content' => 'array',
            'active' => 'boolean',
        ];
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /** Every version (including archived ones) sharing this canevas's lineage. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_id', 'lineage_id')
            ->withTrashed()
            // `id` départage : deux versions enregistrées dans la même
            // seconde ont le même `created_at`, et l'historique les
            // renvoyait alors dans un ordre arbitraire — lisible à
            // l'écran comme une chronologie fausse.
            ->latest('created_at')
            ->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * ADR-244 — les codes des types visés (CDI, CDD… ; maladie…), vide pour un
     * modèle général.
     *
     * @return list<string>
     */
    public function appliesTo(): array
    {
        return array_values($this->applies_to ?? []);
    }

    /** Ce modèle convient-il à ce type de contrat ou de congé ? Un modèle général convient à tous. */
    public function covers(?string $typeCode): bool
    {
        $codes = $this->appliesTo();

        return $codes === [] || ($typeCode !== null && in_array($typeCode, $codes, true));
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
