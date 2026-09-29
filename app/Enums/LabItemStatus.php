<?php

namespace App\Enums;

/**
 * Où en est une analyse demandée à la paillasse (ADR-213, CDC §14 :
 * prélèvement → analyse → résultat → validation).
 *
 * ADR-216 — il n'y a plus de biologiste distinct : le technicien envoie le
 * résultat au médecin, et cet envoi le valide. Les valeurs en base ne changent
 * pas, seul leur sens est dit autrement.
 *
 *   PENDING      rien n'est encore saisi
 *   IN_PROGRESS  des résultats sont saisis, pas encore envoyés
 *   COMPLETED    rendu mais pas encore envoyé : résultat saisi « en un bloc »,
 *                ou analyse terminée avant l'ADR-216
 *   VALIDATED    envoyé au médecin : définitif, il ne se modifie plus
 *   TO_REDO      renvoyée à refaire, avec un motif
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
            self::Completed => 'À envoyer',
            self::Validated => 'Envoyée',
            self::ToRedo => 'À refaire',
        };
    }

    /** La saisie reste ouverte au technicien. */
    public function editable(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::ToRedo], true);
    }
}
