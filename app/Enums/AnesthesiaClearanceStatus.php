<?php

namespace App\Enums;

/**
 * ADR-170 — la décision anesthésique d'autoriser, ou non, le passage au bloc.
 *
 * À ne pas confondre avec `assessment_validated_at`, qui dit seulement que
 * l'évaluation pré-anesthésique est **terminée et verrouillée**. Une évaluation
 * complète peut parfaitement conclure « non autorisé » : ce sont deux faits
 * distincts, et les confondre — ce que faisait `paraclinical_data.surgery_
 * authorized` — revient à déduire une décision clinique d'une saisie complète.
 *
 * ```text
 * DRAFT                    aucune décision prise — jamais « autorisé par défaut »
 * CLEARED                  autorisé
 * CLEARED_WITH_CONDITIONS  autorisé une fois les conditions levées
 * NOT_CLEARED              refusé : l'intervention ne démarre pas
 * DEFERRED                 décision reportée : l'intervention ne démarre pas
 * ```
 *
 * Le CDC §16 ne décrit ni clearance ni checklist : cette notion vient de la
 * demande explicite du propriétaire (2026-09-22), signalée comme telle.
 */
enum AnesthesiaClearanceStatus: string
{
    case Draft = 'DRAFT';
    case Cleared = 'CLEARED';
    case ClearedWithConditions = 'CLEARED_WITH_CONDITIONS';
    case NotCleared = 'NOT_CLEARED';
    case Deferred = 'DEFERRED';

    /** Les décisions que l'anesthésiste peut réellement prononcer. */
    public static function decidable(): array
    {
        return [self::Cleared, self::ClearedWithConditions, self::NotCleared, self::Deferred];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Décision en attente',
            self::Cleared => 'Autorisé',
            self::ClearedWithConditions => 'Autorisé sous conditions',
            self::NotCleared => 'Non autorisé',
            self::Deferred => 'Décision reportée',
        };
    }

    /** Ce que la décision veut dire pour l'équipe chirurgicale, en une phrase. */
    public function description(): string
    {
        return match ($this) {
            self::Draft => 'L’anesthésiste n’a pas encore décidé si l’intervention peut avoir lieu.',
            self::Cleared => 'L’anesthésiste autorise le passage au bloc.',
            self::ClearedWithConditions => 'L’anesthésiste autorise le passage au bloc une fois les conditions levées.',
            self::NotCleared => 'L’anesthésiste refuse le passage au bloc en l’état.',
            self::Deferred => 'L’anesthésiste a reporté sa décision : l’intervention ne peut pas démarrer.',
        };
    }

    /** La pastille de l'écran : jamais la couleur seule, toujours avec son libellé. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Cleared => 'success',
            self::ClearedWithConditions => 'warning',
            self::NotCleared, self::Deferred => 'destructive',
        };
    }

    /**
     * L'intervention peut-elle démarrer de ce seul point de vue ? Les
     * conditions encore ouvertes sont vérifiées à part, par le readiness gate.
     */
    public function allowsIncision(): bool
    {
        return $this === self::Cleared || $this === self::ClearedWithConditions;
    }

    /** Une décision motivée : un refus ou un report sans motif n'apprend rien à l'équipe. */
    public function requiresReason(): bool
    {
        return $this === self::NotCleared || $this === self::Deferred;
    }
}
