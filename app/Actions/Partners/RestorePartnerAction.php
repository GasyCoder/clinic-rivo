<?php

namespace App\Actions\Partners;

use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** ADR-211 — un partenaire archivé revient tel qu'il était. */
class RestorePartnerAction
{
    public function execute(PartnerOrganization $partner, User $actor): void
    {
        if ($actor->cannot('partner_organizations.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer un partenaire.');
        }

        $partner->restore();
    }
}
