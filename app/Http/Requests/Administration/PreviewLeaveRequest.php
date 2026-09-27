<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'leave_type_uuid' => [
                'required', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::LeaveType->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            'starts_on' => ['required', 'date'],
            'returns_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
