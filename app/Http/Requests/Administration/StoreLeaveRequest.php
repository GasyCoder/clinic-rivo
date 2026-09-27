<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;
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
            'leave_type_uuid' => [
                'required', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::LeaveType->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            'leave_address' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:5000'],
            'starts_on' => ['required', 'date'],
            'returns_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'justification' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('interim_employee_uuid')
                && $this->input('employee_uuid') === $this->input('interim_employee_uuid')) {
                $validator->errors()->add('interim_employee_uuid', 'L’intérimaire doit être une autre personne.');
            }

            $leaveType = HrReferenceValue::query()->where('uuid', $this->input('leave_type_uuid'))->first();
            if (($leaveType?->metadata['requires_attachment'] ?? false) && ! $this->hasFile('justification')) {
                $validator->errors()->add('justification', 'Un justificatif est obligatoire pour ce type de demande.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'employee_uuid' => 'demandeur',
            'interim_employee_uuid' => 'intérimaire',
            'leave_type_uuid' => 'type de demande',
            'reason' => 'motif',
            'starts_on' => 'date de départ',
            'returns_on' => 'dernier jour demandé',
            'justification' => 'justificatif',
        ];
    }
}
