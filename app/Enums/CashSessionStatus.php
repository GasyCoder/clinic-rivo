<?php

namespace App\Enums;

enum CashSessionStatus: string
{
    case Open = 'OPEN';
    case Locked = 'LOCKED';
    case Closed = 'CLOSED';
}
