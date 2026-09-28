<?php

namespace App\Enums;

use App\Models\AnalysisCatalog;

/**
 * Comment une analyse se saisit à la paillasse (ADR-213).
 *
 * Repris du laboratoire que la clinique utilisait déjà (labo-vuejs : ses types
 * d'analyse MULTIPLE, DOSAGE, GERME, FV, NEGATIF_POSITIF_1…) : chaque type y
 * choisissait déjà un mode de saisie. Le catalogue historique importé garde ce
 * type dans `source_metadata.type_name` ; une analyse créée dans RIVO le reçoit
 * de son type de résultat. `analysis_catalogs.entry_mode` le fixe explicitement
 * quand on veut autre chose — vide, il se déduit, jamais d'un libellé.
 */
enum LabEntryMode: string
{
    case Numeric = 'NUMERIC';
    case Text = 'TEXT';
    case Choice = 'CHOICE';
    case MultiChoice = 'MULTI_CHOICE';
    case NegativePositive = 'NEG_POS';
    case NegativePositiveValue = 'NEG_POS_VALUE';
    case NegativePositiveChoice = 'NEG_POS_CHOICE';
    case AbsencePresence = 'ABSENCE_PRESENCE';
    case Culture = 'CULTURE';
    case Nugent = 'NUGENT';
    case Label = 'LABEL';

    public function label(): string
    {
        return match ($this) {
            self::Numeric => 'Valeur numérique',
            self::Text => 'Texte libre',
            self::Choice => 'Choix dans une liste',
            self::MultiChoice => 'Plusieurs choix',
            self::NegativePositive => 'Négatif / Positif',
            self::NegativePositiveValue => 'Négatif / Positif + valeur',
            self::NegativePositiveChoice => 'Négatif / Positif + précision',
            self::AbsencePresence => 'Absence / Présence',
            self::Culture => 'Culture — germes et antibiogramme',
            self::Nugent => 'Flore vaginale — score de Nugent',
            self::Label => 'Titre, sans saisie',
        };
    }

    /** Un titre ne porte aucun résultat. */
    public function takesResult(): bool
    {
        return $this !== self::Label;
    }

    /** Une culture et un titre n'ont pas d'interprétation normal / pathologique. */
    public function interpretable(): bool
    {
        return ! in_array($this, [self::Label, self::Culture], true);
    }

    /** Le mode d'une analyse : celui qu'on a fixé, sinon celui de son type historique, sinon de son type de résultat. */
    public static function for(AnalysisCatalog $analysis): self
    {
        if (filled($analysis->entry_mode) && ($mode = self::tryFrom((string) $analysis->entry_mode))) {
            return $mode;
        }

        $hasChoices = count($analysis->predefined_values ?? []) > 0;
        $legacy = self::fromLegacyType(self::legacyTypeName($analysis), $hasChoices);

        if ($legacy !== null) {
            return $legacy;
        }

        return match ($analysis->result_type) {
            'NUMERIC' => self::Numeric,
            'CHOICE' => $hasChoices ? self::Choice : self::Text,
            'BOOLEAN' => self::NegativePositive,
            default => $hasChoices ? self::Choice : self::Text,
        };
    }

    /** Les types d'analyse du laboratoire historique, un par un. `null` : on n'en sait rien. */
    public static function fromLegacyType(?string $type, bool $hasChoices): ?self
    {
        return match (strtoupper((string) $type)) {
            'GERME', 'CULTURE' => self::Culture,
            'FV' => self::Nugent,
            'LABEL' => self::Label,
            'DOSAGE', 'COMPTAGE', 'LEUCOCYTES', 'INPUT_SUFFIXE' => self::Numeric,
            'SELECT' => $hasChoices ? self::Choice : self::Text,
            'SELECT_MULTIPLE' => $hasChoices ? self::MultiChoice : self::Text,
            'NEGATIF_POSITIF_1' => self::NegativePositive,
            'NEGATIF_POSITIF_2' => self::NegativePositiveValue,
            'NEGATIF_POSITIF_3' => $hasChoices ? self::NegativePositiveChoice : self::NegativePositive,
            'ABSENCE_PRESENCE_2' => self::AbsencePresence,
            'INPUT', 'TEST', 'MULTIPLE', 'MULTIPLE_SELECTIF' => $hasChoices ? self::Choice : self::Text,
            default => null,
        };
    }

    public static function legacyTypeName(AnalysisCatalog $analysis): ?string
    {
        $metadata = $analysis->source_metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? ($metadata['type_name'] ?? null) : null;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
