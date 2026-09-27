<?php

namespace App\Actions\StaffAccess;

use App\Models\StaffAccessHandover;
use App\Models\User;
use App\Notifications\StaffAccessReady;
use App\Services\Audit\Auditor;
use App\Services\Auth\AccountActivation;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * ADR-197 / ADR-202 — site : le Super Admin annonce au RH les accès qu'il vient de
 * créer. Chaque compte actif du site qui a `staff_access.receive` reçoit la
 * notification, et dit à chaque employé que son compte existe et où se connecter :
 * l'employé choisit lui-même son mot de passe. Aucun mot de passe n'accompagne l'envoi.
 *
 * Envoyer deux fois ne notifie pas deux fois.
 */
final class SendStaffAccessHandoverAction
{
    public const PERMISSION = 'staff_access.receive';

    public function __construct(private readonly Auditor $auditor) {}

    /** @return array{recipients: int, already: bool} */
    public function execute(StaffAccessHandover $handover, CatalogActor $actor): array
    {
        return DB::transaction(function () use ($handover, $actor): array {
            $handover = StaffAccessHandover::query()->whereKey($handover->getKey())->lockForUpdate()->firstOrFail();

            if ($handover->sent_at !== null) {
                return ['recipients' => 0, 'already' => true];
            }

            $names = $handover->items()->pluck('employee_name')->all();
            if ($names === []) {
                throw ValidationException::withMessages(['handover' => 'Cette remise ne contient aucun accès.']);
            }

            $recipients = self::recipients();
            if ($recipients->isEmpty()) {
                throw ValidationException::withMessages(['handover' => 'Aucun compte actif de ce site n’a le droit « '.self::PERMISSION.' » : accordez-le au RH dans « Rôles & permissions », puis envoyez de nouveau.']);
            }

            $identity = GrantStaffAccessAction::identity($actor);
            $handover->forceFill([
                'sent_at' => now(),
                'sent_by_uuid' => $identity['uuid'],
                'sent_by_name' => $identity['name'],
            ])->save();

            // Le délai de première connexion part de l'annonce au RH : c'est d'ici que
            // l'employé peut apprendre que son compte existe.
            User::query()
                ->whereIn('id', $handover->items()->whereNotNull('user_id')->pluck('user_id'))
                ->whereNull('activated_at')->whereNull('last_login_at')->where('active', true)
                ->update(['activation_open_until' => now()->addDays(AccountActivation::days())]);

            Notification::send($recipients, new StaffAccessReady($handover->uuid, $names, (string) $identity['name'], AccountActivation::days()));

            $this->auditor->record('staff_access.send', entity: $handover, newValues: [
                'accounts' => count($names),
                'recipients' => $recipients->count(),
            ], module: 'administration', actor: $actor->user());

            return ['recipients' => $recipients->count(), 'already' => false];
        });
    }

    /** @return Collection<int, User> Les comptes du site qui annoncent les accès aux employés. */
    public static function recipients(): Collection
    {
        return User::query()->where('active', true)->get()->filter(fn (User $user) => $user->can(self::PERMISSION))->values();
    }
}
