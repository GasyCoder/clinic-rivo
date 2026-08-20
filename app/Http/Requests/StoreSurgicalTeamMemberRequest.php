<?php

namespace App\Http\Requests;

use App\Enums\SurgicalTeamFunction;
use Illuminate\Foundation\Http\FormRequest;
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'function' => ['required', new Enum(SurgicalTeamFunction::class)],
        ];
    }
}
