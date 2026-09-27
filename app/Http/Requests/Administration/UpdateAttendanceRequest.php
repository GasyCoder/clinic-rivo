<?php

namespace App\Http\Requests\Administration;

use App\Models\AttendanceRecord;

class UpdateAttendanceRequest extends AttendanceDataRequest
{
    public function authorize(): bool
    {
        $record = $this->route('attendance');

        return $record instanceof AttendanceRecord
            && ($this->user()?->can('update', $record) ?? false);
    }
}
