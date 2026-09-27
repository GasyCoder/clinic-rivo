<?php

namespace App\Enums;

/**
 * Le parcours d'une prise en charge Maternité (ADR-204).
 *
 * Une consultation prénatale et un accouchement ne se remplissent pas de la
 * même façon : la première suit la grossesse d'un passage à l'autre, le second
 * est le Jour J. Les deux restent des `MaternityRecord` rattachés à la même
 * `Pregnancy` : le jour de l'accouchement retrouve tout le suivi prénatal.
 *
 * Le type est choisi explicitement par la sage-femme, jamais déduit d'un
 * contenu. Un dossier enregistré avant ce choix n'en porte pas (`null`) : il
 * reste lisible, et `inferredFor()` ne sert qu'à l'afficher, jamais à écrire.
 *
 * Le post-natal n'est pas un cas ici : aucun parcours n'est construit pour lui,
 * et un état que rien n'utilise n'aurait pas sa place dans la base.
 */
enum MaternityEncounterType: string
{
    case Prenatal = 'PRENATAL';
    case Delivery = 'DELIVERY';

    public function label(): string
    {
        return match ($this) {
            self::Prenatal => 'Consultation prénatale',
            self::Delivery => 'Accouchement',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Prenatal => 'Consultation',
            self::Delivery => 'Accouchement',
        };
    }

    /** Le geste qui termine ce parcours : un mot qui dit ce qu'on conclut. */
    public function completionLabel(): string
    {
        return match ($this) {
            self::Prenatal => 'Terminer la consultation',
            self::Delivery => 'Clôturer l’accouchement',
        };
    }

    /**
     * Les codes d'actes de la Réception qui laissent attendre ce parcours.
     * Une suggestion de présélection, jamais un choix fait à la place de la
     * sage-femme (ADR-052 : le code est la seule clé).
     *
     * @return list<string>
     */
    public function suggestingCodes(): array
    {
        return match ($this) {
            self::Prenatal => ['MAT-CONSULT-PRENATAL', 'MAT-CONSULT-PRENATAL-SUIVI', 'MAT-DOPPLER'],
            self::Delivery => ['MAT-DELIVERY-SIMPLE', 'MAT-DELIVERY-TWIN'],
        };
    }

    /**
     * Ce que les actes demandés à la Réception suggèrent. Un accouchement
     * l'emporte : une patiente venue accoucher n'est pas en consultation.
     *
     * @param  iterable<string>  $codes
     */
    public static function suggestedFor(iterable $codes): ?self
    {
        $codes = collect($codes)->filter()->values();

        foreach ([self::Delivery, self::Prenatal] as $type) {
            if ($codes->intersect($type->suggestingCodes())->isNotEmpty()) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Pour **afficher** un dossier antérieur à ce choix, jamais pour écrire :
     * un accouchement consigné ou un travail renseigné se lisent comme un
     * accouchement, tout le reste comme une consultation.
     *
     * @param  array<string, mixed>|null  $laborData
     * @param  array<string, mixed>|null  $deliveryData
     */
    public static function inferredFor(?array $laborData, ?array $deliveryData): self
    {
        if (filled($deliveryData['occurred_at'] ?? null)) {
            return self::Delivery;
        }

        $labor = collect($laborData ?? [])->filter(fn ($value) => filled($value) && $value !== 'UNKNOWN');

        return $labor->isNotEmpty() ? self::Delivery : self::Prenatal;
    }

    /** @return list<array{value: string, label: string, short_label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'short_label' => $type->shortLabel(),
        ], self::cases());
    }
}
