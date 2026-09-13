<?php

namespace App\Enums;

/**
 * The body systems offered on the Médecine clinical examination step.
 *
 * ADR-074 had declined to create this grid because no validated list
 * existed — the clinic's paper DOSSIER MÉDICAL contains none, and choosing
 * which systems a doctor examines is a clinical decision, not an interface
 * one. ADR-077 records the owner supplying the list explicitly, which is
 * what lifted that blocker.
 *
 * Held as an enum rather than a table because the list is a clinical
 * vocabulary, not site configuration: a new system changes the meaning of
 * stored records and belongs in a reviewed migration, not in a settings
 * screen. Findings reference it by `system_code`, so a future reordering
 * never rewrites an examination already recorded.
 */
enum ClinicalExamSystem: string
{
    case Cardiovascular = 'CARDIOVASCULAR';
    case Respiratory = 'RESPIRATORY';
    case Digestive = 'DIGESTIVE';
    case Neurological = 'NEUROLOGICAL';
    case Ent = 'ENT';
    case Musculoskeletal = 'MUSCULOSKELETAL';
    case Skin = 'SKIN';
    case Urogenital = 'UROGENITAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Cardiovascular => 'Cardiovasculaire',
            self::Respiratory => 'Respiratoire',
            self::Digestive => 'Digestif / abdominal',
            self::Neurological => 'Neurologique',
            self::Ent => 'ORL',
            self::Musculoskeletal => 'Locomoteur',
            self::Skin => 'Téguments / peau',
            self::Urogenital => 'Urogénital',
            self::Other => 'Autre',
        };
    }

    /** Placeholder guiding what belongs in this system's findings. */
    public function findingsHint(): string
    {
        return match ($this) {
            self::Cardiovascular => 'Bruits du cœur, souffle, rythme, œdèmes, pouls périphériques…',
            self::Respiratory => 'Auscultation, murmure vésiculaire, râles, tirage, toux…',
            self::Digestive => 'Palpation, sensibilité, défense, hépatomégalie, transit…',
            self::Neurological => 'Conscience, motricité, sensibilité, réflexes, signes de localisation…',
            self::Ent => 'Gorge, tympans, fosses nasales, adénopathies cervicales…',
            self::Musculoskeletal => 'Mobilité, douleur articulaire, déformation, force musculaire…',
            self::Skin => 'Lésions, coloration, éruption, plaies, muqueuses…',
            self::Urogenital => 'Fosses lombaires, organes génitaux externes, mictions…',
            self::Other => 'Précisez l’appareil examiné et les constatations.',
        };
    }

    /** Display order; stored on each finding so history stays stable. */
    public function sortOrder(): int
    {
        $index = array_search($this, self::cases(), true);

        return $index === false ? 0 : $index;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
