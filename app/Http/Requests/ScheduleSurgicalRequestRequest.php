<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleSurgicalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'surgeon_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date'],
        ];
    }
}
