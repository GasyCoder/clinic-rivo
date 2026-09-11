<?php

namespace App\Http\Requests\Administration;

use App\Models\GeneratedDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewGeneratedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GeneratedDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_template_uuid' => [
                'required', 'uuid',
                Rule::exists('document_templates', 'uuid')->where('active', true)->whereNull('deleted_at'),
            ],
            'employee_uuid' => ['required', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'employment_contract_uuid' => ['nullable', 'uuid', Rule::exists('employment_contracts', 'uuid')],
            'leave_request_uuid' => ['nullable', 'uuid', Rule::exists('leave_requests', 'uuid')],
            'manual_variables' => ['nullable', 'array'],
            'manual_variables.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
