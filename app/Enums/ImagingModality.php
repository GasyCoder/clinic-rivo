<?php

namespace App\Enums;

use Illuminate\Validation\ValidationException;

/**
 * ADR-106 — la famille d'un examen d'imagerie, réglée au catalogue.
 *
 * Elle n'est **jamais** déduite du code ni du libellé : `HOLTER-ECG` est un
 * enregistrement cardiaque sans commencer par `ECG-`, et un `DOPPLER-MI-ART`
 * est une échographie sans commencer par `ECHO-`. Trois examens sur vingt
 * auraient été mal classés — la faute exacte que l'ADR-052 interdit.
 */
enum ImagingModality: string
{
    case Cardiology = 'CARDIOLOGY';
    case Ultrasound = 'ULTRASOUND';

    public function label(): string
    {
        return match ($this) {
            self::Cardiology => 'ECG / Cardiologie',
            self::Ultrasound => 'Échographie',
        };
    }

    /**
     * La famille enregistrée pour une désignation de ce module : seul un examen
     * d'Imagerie en porte une. Omise, elle reste celle déjà réglée ; envoyée vide,
     * l'examen redevient « non classé ». Hors Imagerie, elle est refusée si on
     * l'envoie, et retirée si la désignation quitte l'Imagerie.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function resolveFor(string $module, array $data, ?self $current = null): ?self
    {
        $sent = array_key_exists('imaging_modality', $data);
        $value = $sent && filled($data['imaging_modality']) ? self::from((string) $data['imaging_modality']) : null;

        if ($module !== CatalogModule::Imaging->value) {
            if ($value !== null) {
                throw ValidationException::withMessages([
                    'imaging_modality' => 'La famille d’imagerie (ECG, échographie) est réservée au module Imagerie.',
                ]);
            }

            return null;
        }

        return $sent ? $value : $current;
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Cardiology => 'ECG',
            self::Ultrasound => 'Échographie',
        };
    }
}
