<?php

namespace App\Enums;

enum SurgicalAwakeningStatus: string
{
    case PerfectlyAwake = 'PERFECTLY_AWAKE';
    case RespondsToRequest = 'RESPONDS_TO_REQUEST';
    case NoSimpleCommandResponse = 'NO_SIMPLE_COMMAND_RESPONSE';
}
