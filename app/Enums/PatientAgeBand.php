<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Bébé, enfant ou adulte, d'après l'âge en années révolues (ADR-184).
 *
 * Les bornes sont des paramètres du site, réglés depuis le portail :
 * bébé jusqu'à `baby_max_age` inclus, enfant jusqu'à `child_max_age` inclus,
 * adulte au-delà. Par défaut : bébé 0–1 an, enfant 2–15 ans, adulte dès 16 ans.
 *
 * Une tranche n'est jamais devinée : sans date de naissance ni âge, il n'y en a
 * pas (`null`).
 */
enum PatientAgeBand: string
{
    case Baby = 'BABY';
    case Child = 'CHILD';
    case Adult = 'ADULT';

    public function label(): string
    {
        return match ($this) {
            self::Baby => 'Bébé',
            self::Child => 'Enfant',
            self::Adult => 'Adulte',
        };
    }

    /** Bébé ou enfant : le profil enfant du formulaire s'applique. */
    public function isMinor(): bool
    {
        return $this !== self::Adult;
    }

    /** @param array{baby_max_age: int, child_max_age: int} $bands */
    public static function forYears(?int $years, array $bands): ?self
    {
        if ($years === null || $years < 0) {
            return null;
        }

        return match (true) {
            $years <= $bands['baby_max_age'] => self::Baby,
            $years <= $bands['child_max_age'] => self::Child,
            default => self::Adult,
        };
    }

    /** L'âge révolu aujourd'hui, depuis une date de naissance ; `null` si elle est illisible ou future. */
    public static function yearsFromBirthDate(mixed $birthDate): ?int
    {
        if (! is_string($birthDate) || trim($birthDate) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($birthDate)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->isFuture() ? null : (int) $date->diffInYears(CarbonImmutable::today());
    }
}
