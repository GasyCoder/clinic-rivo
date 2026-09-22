<?php

namespace App\Enums;

enum SurgicalAwakeningStatus: string
{
    case PerfectlyAwake = 'PERFECTLY_AWAKE';
    case RespondsToRequest = 'RESPONDS_TO_REQUEST';
    case NoSimpleCommandResponse = 'NO_SIMPLE_COMMAND_RESPONSE';

    /** Le libellé que la fiche de sortie du bloc affiche déjà à l'écran. */
    public function label(): string
    {
        return match ($this) {
            self::PerfectlyAwake => 'Parfaitement réveillé',
            self::RespondsToRequest => 'Se réveille à la demande',
            self::NoSimpleCommandResponse => 'Ne répond pas aux ordres simples',
        };
    }
}
