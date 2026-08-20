<?php

namespace App\Enums;

enum BillableItemStatus: string
{
    case Pending = 'PENDING';
    case Invoiced = 'INVOICED';
    case Cancelled = 'CANCELLED';
}
