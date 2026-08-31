<?php

namespace App\Exceptions;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use DomainException;

class InvalidLeaveRequestTransitionException extends DomainException
{
    public function __construct(LeaveRequest $leave, LeaveRequestStatus $target)
    {
        parent::__construct("La demande de congé {$leave->uuid} ne peut pas passer de {$leave->status->value} à {$target->value}.");
    }
}
