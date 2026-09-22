<?php

namespace App\Http\Requests;

use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-168 — la forme de la programmation. Le profil Chirurgien et la présence
 * au planning RH sont jugés par ScheduleSurgicalRequestAction (SurgeonRoster),
 * pour qu'une seule règle vaille quel que soit l'appelant.
 */
class ScheduleSurgicalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activeUser = Rule::exists('users', 'id')->where(fn ($query) => $query
            ->where('active', true)
            ->whereNull('deactivated_at'));

        return [
            'surgeon_id' => ['required', 'integer', $activeUser],
            'assistant_surgeon_ids' => ['sometimes', 'array', 'max:'.ScheduleSurgicalRequestAction::MAX_ASSISTANTS],
            'assistant_surgeon_ids.*' => ['integer', 'distinct', 'different:surgeon_id', $activeUser],
            'scheduled_at' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'surgeon_id' => 'chirurgien principal',
            'assistant_surgeon_ids' => 'chirurgiens aides',
            'assistant_surgeon_ids.*' => 'chirurgien aide',
            'scheduled_at' => 'date et heure',
        ];
    }

    public function messages(): array
    {
        return [
            'assistant_surgeon_ids.*.different' => 'Le chirurgien principal ne peut pas être aussi aide.',
            'assistant_surgeon_ids.*.distinct' => 'Un même chirurgien ne peut être choisi deux fois.',
        ];
    }
}
