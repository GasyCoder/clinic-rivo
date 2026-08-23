<?php

namespace App\Enums;

enum CareCompletionMode: string
{
    case Choice = 'CHOICE';
    case Medicine = 'MEDICINE';
    case Finish = 'FINISH';
}
