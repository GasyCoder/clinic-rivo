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
            'function' => ['required', new Enum(SurgicalTeamFunction::class)],
        ];
    }
}
