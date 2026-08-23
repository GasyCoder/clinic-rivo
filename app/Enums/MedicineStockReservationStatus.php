<?php

namespace App\Enums;

enum MedicineStockReservationStatus: string
{
    case Reserved = 'RESERVED';
    case Released = 'RELEASED';
    case Dispensed = 'DISPENSED';
}
