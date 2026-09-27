<?php

namespace App\Actions\StaffAccess;

use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Auth\AccountActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-202 — un employé a laissé passer le délai de sa première connexion : le RH
 * la rouvre, pour le même délai. Seul un compte jamais utilisé et toujours actif
 * se rouvre ; un compte déjà connecté choisit son mot de passe par « Mot de passe
 * oublié », jamais par son adresse seule.
 */
final class ReopenStaffAccessActivationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(StaffAccessHandoverItem $item, User $actor): User
    {
        return DB::transaction(function () use ($item, $actor): User {
            $user = $item->user_id !== null ? User::query()->whereKey($item->user_id)->lockForUpdate()->first() : null;
            $item->setRelation('user', $user);

            if ($item->activationState() !== StaffAccessHandoverItem::STATE_EXPIRED) {
                throw ValidationException::withMessages(['item' => match ($item->activationState()) {
                    StaffAccessHandoverItem::STATE_ACTIVATED => $item->employee_name.' s’est déjà connecté : rien à rouvrir.',
                    StaffAccessHandoverItem::STATE_WAITING => 'La première connexion de '.$item->employee_name.' est encore ouverte.',
                    default => 'Ce compte n’est plus actif : il ne se rouvre pas d’ici.',
                }]);
            }

            $before = $user->activation_open_until?->toIso8601String();
            $user->forceFill(['activation_open_until' => now()->addDays(AccountActivation::days())])->save();

            $this->auditor->record('staff_access.activation.reopen', entity: $user, newValues: [
                'open_until' => $user->activation_open_until->toIso8601String(),
            ], oldValues: ['open_until' => $before], module: 'administration', actor: $actor);

            return $user;
        });
    }
}
