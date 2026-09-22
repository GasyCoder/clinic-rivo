<?php

namespace App\Enums;

/**
 * ADR-170 — qui confirme un temps de la checklist.
 *
 * Trois rôles, jamais « un compte quelconque » : une checklist de sécurité vaut
 * par le fait que chaque métier a réellement vérifié sa part. Le droit exigé
 * suit le métier — l'anesthésie confirme avec `anesthesia.update`, le
 * chirurgien et l'équipe de salle avec `surgery.preparation.update` — et aucune
 * permission nouvelle n'est créée (CDC §16 en donne déjà une par module).
 */
enum SurgicalChecklistRole: string
{
    case Surgeon = 'SURGEON';
    case Anesthesia = 'ANESTHESIA';
    case Nursing = 'NURSING';

    public function label(): string
    {
        return match ($this) {
            self::Surgeon => 'Chirurgien',
            self::Anesthesia => 'Anesthésiste',
            self::Nursing => 'Équipe de salle',
        };
    }

    /** Le droit que le serveur exigera pour cette confirmation. */
    public function permission(): string
    {
        return match ($this) {
            self::Anesthesia => 'anesthesia.update',
            self::Surgeon, self::Nursing => 'surgery.preparation.update',
        };
    }

    /**
     * La fonction d'équipe de bloc qui tient ce rôle, quand il en existe une
     * (ADR-168). L'équipe de salle regroupe infirmier de bloc et paramédical :
     * elle n'a pas une fonction unique et n'est donc pas nominative ici.
     */
    public function teamFunction(): ?SurgicalTeamFunction
    {
        return match ($this) {
            self::Surgeon => SurgicalTeamFunction::Surgeon,
            self::Anesthesia => SurgicalTeamFunction::Anesthetist,
            self::Nursing => null,
        };
    }
}
