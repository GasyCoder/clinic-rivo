<?php

namespace App\Enums;

enum CareOrderStatus: string
{
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
}
