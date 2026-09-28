<?php

namespace App\Enums;

/**
 * Où en est une analyse demandée, à la paillasse puis chez le biologiste
 * (ADR-213, CDC §14 : prélèvement → analyse → résultat → validation).
 *
 *   PENDING      rien n'est encore saisi
 *   IN_PROGRESS  des résultats sont saisis, l'analyse n'est pas terminée
 *   COMPLETED    le technicien l'a terminée — elle attend la validation
 *   VALIDATED    le biologiste l'a validée : elle ne se modifie plus
 *   TO_REDO      renvoyée au technicien, avec le motif du biologiste
 */
enum LabItemStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Validated = 'VALIDATED';
    case ToRedo = 'TO_REDO';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À analyser',
            self::InProgress => 'En cours',
            self::Completed => 'À valider',
            self::Validated => 'Validée',
            self::ToRedo => 'À refaire',
        };
    }

    /** La saisie reste ouverte au technicien. */
    public function editable(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::ToRedo], true);
    }
}
