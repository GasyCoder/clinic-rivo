<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La feuille de compte rendu proposée d'office pour un examen d'imagerie,
 * réglée par les médecins du site (ADR-108). `template_key` nul : aucune.
 */
#[Fillable(['catalog_item_id', 'template_key', 'created_by', 'updated_by'])]
class ImagingExamReportTemplate extends Model
{
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
