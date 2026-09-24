<?php

namespace App\Enums;

/**
 * ADR-177 — la prochaine étape que la Réception **suggère** pour un passage.
 *
 * Ce n'est ni une orientation, ni une autorisation : une indication, facultative,
 * que l'équipe lit. Un passage peut n'en porter aucune — c'est un état normal —
 * ou plusieurs. Elle ne cache le passage à aucun service autorisé et ne crée
 * aucune `EpisodeOrientation` : la vraie prise en charge et la vraie orientation
 * clinique restent des actions métier distinctes.
 *
 * Les valeurs sont celles de `CatalogModule`, pour qu'un service se reconnaisse
 * sans table de correspondance.
 */
enum ReceptionNextStep: string
{
    case Care = 'CARE';
    case Medicine = 'MEDICINE';
    case Maternity = 'MATERNITY';
    case Laboratory = 'LABORATORY';
    case Pharmacy = 'PHARMACY';
    case Surgery = 'SURGERY';

    public function label(): string
    {
        return $this->module()->label();
    }

    public function module(): CatalogModule
    {
        return CatalogModule::from($this->value);
    }

    public static function fromModule(CatalogModule $module): ?self
    {
        return self::tryFrom($module->value);
    }

    /**
     * Les choix proposés à l'écran, dans l'ordre du parcours habituel d'un
     * patient. Servis par le serveur : Vue ne recopie pas la liste.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $step): array => ['value' => $step->value, 'label' => $step->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $step): string => $step->value, self::cases());
    }
}
