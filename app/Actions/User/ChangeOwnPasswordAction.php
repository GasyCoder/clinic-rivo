<?php

namespace App\Actions\User;

use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ADR-184 — un utilisateur change son propre mot de passe depuis « Mon profil ».
 *
 * Seul le mot de passe se change ici : le nom, l'email, le rôle et les droits
 * restent gérés par l'administrateur (ADR-022). L'ancien mot de passe est
 * vérifié par la requête ; la politique est la même que partout ailleurs
 * (`SecurePassword`).
 *
 * Les autres sessions ouvertes au nom de ce compte sont fermées — un mot de
 * passe changé parce qu'il a pu être vu ne doit pas laisser une session ouverte
 * ailleurs —, la session en cours est gardée. Le jeton « se souvenir de moi »
 * est renouvelé pour la même raison. Audité sans jamais écrire le mot de passe.
 */
class ChangeOwnPasswordAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @return int le nombre d'autres sessions fermées */
    public function execute(User $user, string $password, ?string $currentSessionId): int
    {
        return DB::transaction(function () use ($user, $password, $currentSessionId): int {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $revoked = 0;

            if (config('session.driver') === 'database') {
                $revoked = DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->when($currentSessionId, fn ($query) => $query->where('id', '!=', $currentSessionId))
                    ->delete();
            }

            $this->auditor->record(
                'user.password.change',
                entity: $user,
                newValues: ['other_sessions_closed' => $revoked],
                module: 'auth',
                actor: $user,
            );

            return $revoked;
        });
    }
}
