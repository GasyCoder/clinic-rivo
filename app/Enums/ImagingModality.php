<?php

namespace App\Enums;

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

    public function shortLabel(): string
    {
        return match ($this) {
            self::Cardiology => 'ECG',
            self::Ultrasound => 'Échographie',
        };
    }
}
