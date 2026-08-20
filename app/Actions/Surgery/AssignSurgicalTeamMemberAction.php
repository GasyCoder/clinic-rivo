<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalTeamFunction;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AssignSurgicalTeamMemberAction
{
    public function execute(SurgicalRequest $surgicalRequest, User $user, SurgicalTeamFunction $function): SurgicalTeamMember
    {
        return $surgicalRequest->teamMembers()->create([
            'user_id' => $user->getKey(),
            'function' => $function,
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
        ]);
    }
}
