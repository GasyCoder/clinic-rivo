<?php

namespace App\Http\Requests;

use App\Enums\AdministrativeExitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CDC §33.3 — shape of the exit form. The *rules* of which exit is legal
 * live in RecordAdministrativeExitAction, which recomputes the balance
 * itself: a FormRequest is reachable only through this one route, an
 * Action is reachable from anywhere.
 */
class RecordAdministrativeExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('episodes.administrative_exit') ?? false;
    }

    public function rules(): array
    {
        $isDebt = $this->input('exit_type') === AdministrativeExitType::DebtValidated->value;
        $isEscape = $this->input('exit_type') === AdministrativeExitType::Escaped->value;

        return [
            'exit_type' => ['required', Rule::in(AdministrativeExitType::values())],
            // §34.1 règle 8 exige que toute opération sensible porte sa
            // cause. Elle n'exige pas qu'un agent la tape : laissé vide,
            // le motif est composé par l'Action à partir du compte qu'elle
            // vient de recalculer sous verrou — donc jamais un montant que
            // le compte contredit, et jamais un « RAS » saisi pour franchir
            // un champ obligatoire. Ce que l'agent écrit l'emporte toujours.
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:2000'],

            // §33.3 "dette validée" — informations obligatoires: personne
            // responsable du paiement et ses coordonnées. Échéance et
            // commentaire restent facultatifs ("échéance éventuelle").
            'responsible_name' => [Rule::requiredIf($isDebt), 'nullable', 'string', 'max:255'],
            'responsible_phone' => [Rule::requiredIf($isDebt), 'nullable', 'string', 'max:50'],
            'responsible_relationship' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date'],

            // §33.3 "évadé" — date et heure estimées, service, observations.
            // The estimate may not be in the future: it describes a
            // departure already observed.
            'left_at_estimate' => [Rule::requiredIf($isEscape), 'nullable', 'date', 'before_or_equal:now'],
            'last_known_service' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'exit_type' => 'type de sortie',
            'reason' => 'motif',
            'responsible_name' => 'personne responsable du paiement',
            'responsible_phone' => 'coordonnées du responsable',
            'responsible_relationship' => 'lien avec le patient',
            'due_date' => 'échéance',
            'left_at_estimate' => 'heure estimée du départ',
            'last_known_service' => 'service',
        ];
    }
}
