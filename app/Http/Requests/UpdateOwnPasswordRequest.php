<?php

namespace App\Http\Requests;

use App\Support\SecurePassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-184 — le changement de son propre mot de passe : l'ancien est exigé,
 * le nouveau suit la politique commune et ne peut pas être le même.
 */
class UpdateOwnPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Son propre compte : aucun droit à demander, la route exige déjà la connexion.
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', SecurePassword::rule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
            'password.required' => 'Saisissez le nouveau mot de passe.',
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l’actuel.',
        ];
    }
}
