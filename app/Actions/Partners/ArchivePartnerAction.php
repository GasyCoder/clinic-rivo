<?php

namespace App\Actions\Partners;

use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ADR-211 — archiver un partenaire (ADR-009) : il n'est plus proposé à
 * l'accueil, mais les passages qui le citent gardent leur instantané et son
 * dossier patient reste relié. Rien n'est supprimé ; il se restaure.
 */
class ArchivePartnerAction
{
    public function execute(PartnerOrganization $partner, string $reason, User $actor): void
    {
        if ($actor->cannot('partner_organizations.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver un partenaire.');
        }

        $partner->delete_reason = $reason;
        $partner->delete();
    }
}
