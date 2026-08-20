<?php

namespace App\Http\Requests;

use App\Enums\VisitorCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreVisitorVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'category' => ['required', new Enum(VisitorCategory::class)],
            'organization' => [
                'nullable',
                'string',
                'max:255',
                'required_if:category,'.VisitorCategory::Professional->value,
                'prohibited_unless:category,'.VisitorCategory::Professional->value,
            ],
            'professional_attachments' => [
                'nullable',
                'array',
                'max:4',
                'prohibited_unless:category,'.VisitorCategory::Professional->value,
            ],
            'professional_attachments.*' => [
                'bail',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:5120',
            ],
            'patient_uuid' => [
                'nullable',
                'uuid',
                'prohibited_unless:category,'.VisitorCategory::PatientOrFamilyVisit->value,
                Rule::exists('patients', 'uuid')->whereNull('deleted_at'),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'nom complet',
            'phone' => 'téléphone',
            'category' => 'catégorie',
            'organization' => 'organisme',
            'professional_attachments' => 'pièces jointes professionnelles',
            'professional_attachments.*' => 'pièce jointe professionnelle',
            'patient_uuid' => 'patient concerné',
            'reason' => 'motif',
        ];
    }
}
