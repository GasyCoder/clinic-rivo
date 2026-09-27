<?php

namespace App\Http\Requests\Administration;

use App\Models\AttendanceRecord;

class StoreAttendanceRequest extends AttendanceDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AttendanceRecord::class) ?? false;
    }
}
