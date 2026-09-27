<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Une feuille de compte rendu d'imagerie créée par les médecins du site
 * (ADR-108). Elle s'ajoute aux feuilles papier de la clinique, qui restent
 * dans `ImagingReportTemplates`.
 *
 * Retirée par archivage, jamais supprimée : un compte rendu qui l'a utilisée
 * garde son propre texte, mais l'historique doit rester lisible (ADR-009).
 */
#[Fillable(['name', 'description', 'body_html', 'created_by', 'updated_by'])]
class ImagingReportTemplate extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function auditModule(): ?string
    {
        return 'medicine';
    }
}
