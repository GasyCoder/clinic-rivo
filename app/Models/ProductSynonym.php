<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-241 — une abréviation du site : « pcm » se lit « paracetamol ». Elle
 * s'ajoute au dictionnaire livré avec RIVO (ProductLabel::BUILT_IN_SYNONYMS)
 * et l'emporte sur lui.
 */
#[Fillable(['term', 'canonical', 'note', 'created_by', 'external_created_by_uuid', 'external_created_by_name'])]
class ProductSynonym extends Model
{
    use Auditable, HasUuid;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
