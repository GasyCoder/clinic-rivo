<?php

namespace App\Http\Requests;

use App\Enums\SurgicalTeamFunction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreSurgicalTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deactivated_at')),
            ],
            // ADR-168 — un chirurgien entre dans l'équipe par la programmation
            // (profil Chirurgien, planning RH), jamais par ce formulaire libre.
            'function' => [
                'required',
                new Enum(SurgicalTeamFunction::class),
                Rule::notIn([SurgicalTeamFunction::Surgeon->value]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'function.not_in' => 'Les chirurgiens se choisissent avec la programmation de l’intervention (profil Chirurgien, planning RH).',
        ];
    }
}
