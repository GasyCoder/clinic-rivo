<?php

namespace App\Http\Requests;

use App\Actions\Surgery\ScheduleSurgicalRequestAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corriger la programmation pendant l'intervention : date, principal, aides,
 * opérateur réel — chacun omis reste tel quel.
 * La forme est jugée ici ; le statut, le profil et la cohérence de l'équipe le
 * sont par AdjustSurgicalTeamDuringInterventionAction.
 */
class AdjustSurgicalTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('surgery.schedule') === true;
    }

    public function rules(): array
    {
        $activeUser = Rule::exists('users', 'id')->where(fn ($query) => $query
            ->where('active', true)
            ->whereNull('deactivated_at'));

        return [
            'scheduled_at' => ['sometimes', 'date'],
            'surgeon_id' => ['sometimes', 'integer', $activeUser],
            'assistant_surgeon_ids' => ['sometimes', 'array', 'max:'.ScheduleSurgicalRequestAction::MAX_ASSISTANTS],
            'assistant_surgeon_ids.*' => ['integer', 'distinct', $activeUser],
            'performed_by' => ['nullable', 'integer', $activeUser],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'scheduled_at' => 'date programmée',
            'surgeon_id' => 'chirurgien principal',
            'assistant_surgeon_ids' => 'chirurgiens aides',
            'assistant_surgeon_ids.*' => 'chirurgien aide',
            'performed_by' => 'opérateur',
            'reason' => 'motif',
        ];
    }
}
