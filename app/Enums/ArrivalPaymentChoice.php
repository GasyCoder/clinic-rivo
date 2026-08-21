<?php

namespace App\Enums;

enum ArrivalPaymentChoice: string
{
    case Now = 'NOW';
    case Later = 'LATER';
}
