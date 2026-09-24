<?php

namespace App\Http\Requests\Reception;

use App\Enums\ReceptionNextStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-177 — la prochaine étape suggérée, modifiée après l'accueil.
 *
 * Aucune case cochée est une réponse valide : elle efface la suggestion. Il
 * n'existe donc aucune règle « obligatoire », et la liste n'autorise que les
 * services que la Réception peut suggérer.
 */
class UpdateEpisodeNextStepsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('episodes.update');
    }

    public function rules(): array
    {
        return [
            'next_steps' => ['present', 'nullable', 'array', 'max:'.count(ReceptionNextStep::cases())],
            'next_steps.*' => ['string', 'distinct', Rule::enum(ReceptionNextStep::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'next_steps' => 'prochaine étape suggérée',
            'next_steps.*' => 'prochaine étape suggérée',
        ];
    }
}
