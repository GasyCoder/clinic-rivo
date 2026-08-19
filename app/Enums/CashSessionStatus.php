<?php

namespace App\Enums;

enum CashSessionStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
