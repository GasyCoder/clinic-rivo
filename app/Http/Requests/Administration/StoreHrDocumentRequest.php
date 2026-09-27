<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrDocumentCategory;
use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreHrDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'notes'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $value = str($this->input($field))->squish()->toString();
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', HrDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'employment_contract_uuid' => ['nullable', 'uuid', Rule::exists('employment_contracts', 'uuid')->whereNull('deleted_at')],
            'leave_request_uuid' => ['nullable', 'uuid', Rule::exists('leave_requests', 'uuid')],
            'attestation_type_uuid' => [
                Rule::requiredIf($this->input('category') === HrDocumentCategory::Attestation->value),
                'nullable', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::AttestationType->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            'category' => ['required', new Enum(HrDocumentCategory::class)],
            'title' => ['required', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'file' => [
                'required', 'file', 'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $employeeId = Employee::query()
                ->where('uuid', $this->input('employee_uuid'))->value('id');

            if ($this->filled('employment_contract_uuid')) {
                $belongs = EmploymentContract::query()
                    ->where('uuid', $this->input('employment_contract_uuid'))
                    ->where('employee_id', $employeeId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('employment_contract_uuid', 'Ce contrat n’appartient pas à cet employé.');
                }
            }

            if ($this->filled('leave_request_uuid')) {
                $belongs = LeaveRequest::query()
                    ->where('uuid', $this->input('leave_request_uuid'))
                    ->where('employee_id', $employeeId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('leave_request_uuid', 'Cette demande de congé n’appartient pas à cet employé.');
                }
            }
        }];
    }
}
