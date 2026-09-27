<?php

namespace App\Http\Requests;

use App\Models\AnesthesiaClearanceCondition;
use Illuminate\Foundation\Http\FormRequest;

class ResolveAnesthesiaClearanceConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $condition = $this->route('condition');

        return $condition instanceof AnesthesiaClearanceCondition
            && $this->user()?->can('resolve', $condition) === true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
