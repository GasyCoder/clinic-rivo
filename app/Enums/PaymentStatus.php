<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
