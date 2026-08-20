<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalTeamMember;
use App\Services\Audit\Auditor;

/**
 * No dedicated CDC permission exists for removing a team member (same gap
 * as assignment — see AssignSurgicalTeamMemberAction), gated behind the
 * generic surgery.update. SurgicalTeamMember has no SoftDeletable (it is a
 * roster assignment, not critical clinical/financial data per ADR-010), so
 * this is a real delete — recorded explicitly here since Auditable only
 * covers create/update, not delete, for models without SoftDeletable.
 */
class RemoveSurgicalTeamMemberAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(SurgicalTeamMember $member): void
    {
        $this->auditor->record('delete', entity: $member, module: 'surgery');

        $member->delete();
    }
}
