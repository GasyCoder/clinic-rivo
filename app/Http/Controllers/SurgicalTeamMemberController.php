<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\AssignSurgicalTeamMemberAction;
use App\Actions\Surgery\RemoveSurgicalTeamMemberAction;
use App\Enums\SurgicalTeamFunction;
use App\Http\Requests\StoreSurgicalTeamMemberRequest;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class SurgicalTeamMemberController extends Controller
{
    public function store(StoreSurgicalTeamMemberRequest $request, SurgicalRequest $surgicalRequest, AssignSurgicalTeamMemberAction $action): RedirectResponse
    {
        $user = User::query()->findOrFail($request->validated('user_id'));

        $action->execute($surgicalRequest, $user, SurgicalTeamFunction::from($request->validated('function')));

        return back()->with('status', "Membre d'équipe ajouté.");
    }

    public function destroy(SurgicalRequest $surgicalRequest, SurgicalTeamMember $teamMember, RemoveSurgicalTeamMemberAction $action): RedirectResponse
    {
        $action->execute($teamMember);

        return back()->with('status', "Membre d'équipe retiré.");
    }
}
