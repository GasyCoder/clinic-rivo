<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Enums\LeaveDayCountMethod;
use App\Models\HrReferenceValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class HrReferenceDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['code', 'label'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $this->merge([$field => str($this->input($field))->squish()->toString()]);
            }
        }

        // Un type de contrat ne porte qu'un réglage : « contrat de stage ».
        // Les règles de congé ne le concernent pas.
        if ($this->input('type') === HrReferenceType::ContractType->value && is_array($this->input('metadata'))) {
            $this->merge(['metadata' => ['internship' => $this->boolean('metadata.internship')]]);
        }
    }

    public function authorize(): bool
    {
        $reference = $this->route('reference');

        return $reference instanceof HrReferenceValue
            ? ($this->user()?->can('update', $reference) ?? false)
            : ($this->user()?->can('create', HrReferenceValue::class) ?? false);
    }

    public function attributes(): array
    {
        return [
            'department_uuids' => 'départements',
            'department_uuids.*' => 'département',
            'metadata.internship' => 'contrat de stage',
        ];
    }

    public function rules(): array
    {
        $reference = $this->route('reference');
        $isLeaveType = fn (): bool => $this->input('type') === HrReferenceType::LeaveType->value;
        $isContractType = $this->input('type') === HrReferenceType::ContractType->value;
        $isJobTitle = fn (): bool => $this->input('type') === HrReferenceType::JobTitle->value;

        return [
            'type' => ['required', new Enum(HrReferenceType::class)],
            'code' => [
                'required', 'string', 'max:80',
                Rule::unique('hr_reference_values', 'code')
                    ->where('type', $this->input('type'))
                    ->ignore($reference instanceof HrReferenceValue ? $reference : null),
            ],
            'label' => [
                'required', 'string', 'max:255',
                Rule::unique('hr_reference_values', 'label')
                    ->where('type', $this->input('type'))
                    ->ignore($reference instanceof HrReferenceValue ? $reference : null),
            ],
            'active' => ['required', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // ADR-194 — un type de contrat peut être marqué « contrat de stage ».
            'metadata' => $isContractType
                ? ['sometimes', 'array']
                : [Rule::excludeUnless($isLeaveType), 'required', 'array'],
            'metadata.internship' => $isContractType ? ['sometimes', 'boolean'] : ['exclude'],
            // ADR-194 — les départements où cette fonction existe. Omettre la
            // clé laisse les liens tels quels ; une liste vide les retire tous.
            'department_uuids' => [Rule::excludeUnless($isJobTitle), 'sometimes', 'array', 'max:100'],
            'department_uuids.*' => [
                Rule::excludeUnless($isJobTitle), 'uuid', 'distinct',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::Department->value)
                    ->whereNull('deleted_at'),
            ],
            'metadata.consumes_annual_balance' => [Rule::excludeUnless($isLeaveType), 'required', 'boolean'],
            'metadata.annual_quota_days' => [
                Rule::excludeUnless($isLeaveType),
                Rule::requiredIf(fn () => $this->boolean('metadata.consumes_annual_balance')),
                'nullable', 'numeric', 'gt:0', 'max:366',
            ],
            'metadata.max_days_per_request' => [Rule::excludeUnless($isLeaveType), 'nullable', 'numeric', 'gt:0', 'max:366'],
            'metadata.requires_attachment' => [Rule::excludeUnless($isLeaveType), 'required', 'boolean'],
            'metadata.requires_approval' => [Rule::excludeUnless($isLeaveType), 'required', 'boolean'],
            'metadata.day_count_method' => [Rule::excludeUnless($isLeaveType), 'required', new Enum(LeaveDayCountMethod::class)],
        ];
    }
}
