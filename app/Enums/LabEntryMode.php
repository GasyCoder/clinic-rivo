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

    /**
     * ADR-238 — les types de résultat auxquels ce mode convient. Le premier
     * mode de chaque type (dans l'ordre des cas) est son mode par défaut.
     *
     * @return array<int, string>
     */
    public function resultTypes(): array
    {
        return match ($this) {
            self::Numeric => ['NUMERIC'],
            self::Text, self::Culture, self::Nugent, self::Label => ['TEXT'],
            self::Choice, self::MultiChoice, self::NegativePositiveChoice => ['CHOICE'],
            self::NegativePositive, self::NegativePositiveValue, self::AbsencePresence => ['CHOICE', 'BOOLEAN'],
        };
    }

    public function fitsResultType(?string $resultType): bool
    {
        return in_array((string) $resultType, $this->resultTypes(), true);
    }

    /**
     * Le type de résultat qui dit le mieux ce mode, quand un mode ne va pas à
     * son type : le premier qu'il accepte.
     */
    public function homeResultType(): string
    {
        return $this->resultTypes()[0];
    }

    /** @return array<int, self> Les modes qu'un type de résultat accepte, le mode par défaut en tête. */
    public static function forResultType(?string $resultType): array
    {
        return array_values(array_filter(self::cases(), fn (self $mode) => $mode->fitsResultType($resultType)));
    }

    /**
     * Le mode d'une analyse : celui qu'on a fixé, sinon celui de son type
     * historique s'il va à son type de résultat, sinon celui de son type de
     * résultat. Un type historique qui ne va plus au type choisi n'est plus lu :
     * changer le type change la saisie (ADR-238).
     */
    public static function for(AnalysisCatalog $analysis): self
    {
        if (filled($analysis->entry_mode) && ($mode = self::tryFrom((string) $analysis->entry_mode))) {
            return $mode;
        }

        $hasChoices = count($analysis->predefined_values ?? []) > 0;
        $legacy = self::fromLegacyType(self::legacyTypeName($analysis), $hasChoices);

        if ($legacy !== null && $legacy->fitsResultType($analysis->result_type)) {
            return $legacy;
        }

        return self::fromResultType($analysis->result_type, $hasChoices);
    }

    /** Le mode d'un type de résultat, sans type historique. Un choix sans valeur se saisit en texte. */
    public static function fromResultType(?string $resultType, bool $hasChoices): self
    {
        return match ($resultType) {
            'NUMERIC' => self::Numeric,
            'CHOICE' => $hasChoices ? self::Choice : self::Text,
            'BOOLEAN' => self::NegativePositive,
            default => $hasChoices && $resultType !== 'TEXT' ? self::Choice : self::Text,
        };
    }

    /** D'où vient le mode d'une analyse : `explicit`, `legacy` (type historique) ou `result_type`. */
    public static function sourceFor(AnalysisCatalog $analysis): string
    {
        if (filled($analysis->entry_mode) && self::tryFrom((string) $analysis->entry_mode)) {
            return 'explicit';
        }

        $legacy = self::fromLegacyType(self::legacyTypeName($analysis), count($analysis->predefined_values ?? []) > 0);

        return $legacy !== null && $legacy->fitsResultType($analysis->result_type) ? 'legacy' : 'result_type';
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

    /** @return array<int, array{value: string, label: string, result_types: array<int, string>}> */
    public static function options(): array
    {
        return array_map(fn (self $mode) => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'result_types' => $mode->resultTypes(),
        ], self::cases());
    }
}
