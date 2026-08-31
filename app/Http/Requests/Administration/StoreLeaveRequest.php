<?php

namespace App\Http\Requests\Administration;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['leave_address', 'emergency_phone', 'reason'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $value = str($this->input($field))->squish()->toString();
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'interim_employee_uuid' => ['nullable', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'leave_address' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'days_requested' => ['nullable', 'numeric', 'gt:0', 'max:9999.99'],
            'remaining_days_snapshot' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'reason' => ['required', 'string', 'max:5000'],
            'requested_on' => ['required', 'date'],
            'starts_on' => ['required', 'date'],
            'returns_on' => ['required', 'date', 'after:starts_on'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('interim_employee_uuid')
                && $this->input('employee_uuid') === $this->input('interim_employee_uuid')) {
                $validator->errors()->add('interim_employee_uuid', 'L’intérimaire doit être une autre personne.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'employee_uuid' => 'demandeur',
            'interim_employee_uuid' => 'intérimaire',
            'days_requested' => 'congé à prendre',
            'remaining_days_snapshot' => 'reste à prendre',
            'reason' => 'motif',
            'requested_on' => 'date de demande',
            'starts_on' => 'date de départ',
            'returns_on' => 'date de retour',
        ];
    }
}
