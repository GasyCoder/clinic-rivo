<?php

namespace App\Enums;

enum CareOrderStatus: string
{
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
    // Every act withdrawn by the doctor before Soins took the patient.
    case Cancelled = 'CANCELLED';
}
