<?php

namespace App\Actions\Medicine;

use App\Models\ImagingReportTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ADR-108 — retire une feuille ajoutée par les médecins du site.
 *
 * Archivage, jamais suppression (ADR-009). Les comptes rendus déjà écrits
 * gardent leur texte : la feuille n'est qu'un point de départ. `SoftDeletable`
 * trace l'archivage, son auteur et son motif.
 */
class ArchiveImagingReportTemplateAction
{
    public const DEFAULT_REASON = 'Feuille retirée depuis la fenêtre de compte rendu';

    public function execute(ImagingReportTemplate $template, User $actor): void
    {
        if ($actor->cannot('imaging_templates.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas retirer de feuille de compte rendu.');
        }

        $template->delete_reason = self::DEFAULT_REASON;
        $template->delete();
    }
}
