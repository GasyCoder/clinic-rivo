<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-197 / ADR-202 — un accès annoncé au RH : le compte créé et son adresse. Le
 * nom, la fonction et le rôle sont des instantanés : la liste se relit telle
 * qu'elle a été envoyée. Où en est l'employé se lit sur son compte, jamais ici.
 *
 * `secret` ne reçoit plus rien depuis l'ADR-202 (colonne gardée pour l'historique).
 */
#[Fillable([
    'uuid', 'staff_access_handover_id', 'employee_id', 'user_id', 'employee_name', 'employee_number', 'job_title',
    'login_email', 'mailbox_address', 'role_label', 'profile_label', 'secret', 'mailbox_shares_password',
])]
#[Hidden(['secret'])]
class StaffAccessHandoverItem extends Model
{
    use HasUuid;

    public const STATE_ACTIVATED = 'ACTIVATED';

    public const STATE_WAITING = 'WAITING';

    public const STATE_EXPIRED = 'EXPIRED';

    public const STATE_DISABLED = 'DISABLED';

    public const STATE_REMOVED = 'REMOVED';

    protected function casts(): array
    {
        return [
            // Chiffré avec la clé de l'application : jamais lisible en base.
            'secret' => 'encrypted',
            'mailbox_shares_password' => 'boolean',
        ];
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(StaffAccessHandover::class, 'staff_access_handover_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Où en est l'employé : connecté, en attente de sa première connexion, délai
     * dépassé (à rouvrir), compte désactivé, ou compte retiré.
     */
    public function activationState(): string
    {
        $user = $this->user;

        return match (true) {
            $user === null => self::STATE_REMOVED,
            $user->activated_at !== null || $user->last_login_at !== null => self::STATE_ACTIVATED,
            ! $user->isActive() => self::STATE_DISABLED,
            $user->awaitsActivation() => self::STATE_WAITING,
            default => self::STATE_EXPIRED,
        };
    }

    public static function stateLabel(string $state): string
    {
        return [
            self::STATE_ACTIVATED => 'Connecté',
            self::STATE_WAITING => 'Première connexion attendue',
            self::STATE_EXPIRED => 'Délai dépassé',
            self::STATE_DISABLED => 'Compte désactivé',
            self::STATE_REMOVED => 'Compte retiré',
        ][$state] ?? $state;
    }
}
