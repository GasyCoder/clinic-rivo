<?php

namespace App\Enums;

/**
 * ADR-170 — les trois temps de la checklist de sécurité du bloc.
 *
 * ```text
 * SIGN_IN   avant l'induction anesthésique
 * TIME_OUT  juste avant l'incision — la dernière vérification d'équipe
 * SIGN_OUT  avant que l'équipe quitte la salle
 * ```
 *
 * Le CDC §16 ne décrit aucune checklist : les trois temps viennent de la
 * demande du propriétaire (2026-09-22). Leur **contenu** n'est pas écrit dans
 * le code comme une règle médicale — voir `SurgicalSafetyChecklistItems`, dont
 * les items sont un point de départ à faire valider par la clinique.
 */
enum SurgicalChecklistPhase: string
{
    case SignIn = 'SIGN_IN';
    case TimeOut = 'TIME_OUT';
    case SignOut = 'SIGN_OUT';

    public function label(): string
    {
        return match ($this) {
            self::SignIn => 'SIGN IN',
            self::TimeOut => 'TIME OUT',
            self::SignOut => 'SIGN OUT',
        };
    }

    public function moment(): string
    {
        return match ($this) {
            self::SignIn => 'Avant l’induction anesthésique',
            self::TimeOut => 'Juste avant l’incision',
            self::SignOut => 'Avant que l’équipe quitte la salle',
        };
    }

    /**
     * Les rôles qui doivent confirmer ce temps.
     *
     * SIGN IN se fait entre l'anesthésiste et l'équipe qui reçoit le patient ;
     * le chirurgien n'y est pas toujours présent et exiger sa confirmation
     * bloquerait l'induction pour une signature qui ne dit rien de plus.
     * TIME OUT et SIGN OUT réunissent les trois : c'est tout leur objet.
     *
     * @return array<int, SurgicalChecklistRole>
     */
    public function requiredRoles(): array
    {
        return match ($this) {
            self::SignIn => [SurgicalChecklistRole::Anesthesia, SurgicalChecklistRole::Nursing],
            self::TimeOut, self::SignOut => [
                SurgicalChecklistRole::Surgeon,
                SurgicalChecklistRole::Anesthesia,
                SurgicalChecklistRole::Nursing,
            ],
        };
    }
}
