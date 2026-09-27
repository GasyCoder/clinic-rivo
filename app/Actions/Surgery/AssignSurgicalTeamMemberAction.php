<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalTeamFunction;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * ADR-168 — un membre de l'équipe de bloc tient une fonction que son profil
 * métier lui donne : anesthésiste, infirmier de bloc, paramédical, chirurgien.
 * Revérifié ici, jamais seulement dans la liste de l'écran.
 */
class AssignSurgicalTeamMemberAction
{
    public function execute(SurgicalRequest $surgicalRequest, User $user, SurgicalTeamFunction $function): SurgicalTeamMember
    {
        $profile = $user->professionalProfile;

        if (! $profile || ! $profile->active || $profile->code !== $function->profileCode()) {
            throw ValidationException::withMessages([
                'user_id' => "« {$user->name} » n’a pas le profil métier {$function->label()} : il ne peut pas tenir cette fonction au bloc.",
            ]);
        }

        $alreadyThere = $surgicalRequest->teamMembers()
            ->where('user_id', $user->getKey())
            ->where('function', $function->value)
            ->exists();

        if ($alreadyThere) {
            throw ValidationException::withMessages([
                'user_id' => "« {$user->name} » est déjà dans l’équipe comme {$function->label()}.",
            ]);
        }

        return $surgicalRequest->teamMembers()->create([
            'user_id' => $user->getKey(),
            'function' => $function,
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
        ]);
    }
}
