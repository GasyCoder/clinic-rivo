<?php

namespace App\Http\Requests\Administration;

use App\Models\ProfessionalProfile;
use App\Support\SecurePassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function authorize(): bool
    {
        return ($this->user()?->can('users.create') ?? false)
            && ($this->user()?->can('roles.assign') ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', SecurePassword::rule()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'professional_profile_id' => [
                Rule::requiredIf(fn () => ProfessionalProfile::query()
                    ->active()
                    ->where('role_id', $this->integer('role_id'))
                    ->exists()),
                'nullable',
                'integer',
                Rule::exists('professional_profiles', 'id')->where(
                    fn ($query) => $query
                        ->where('role_id', $this->integer('role_id'))
                        ->where('active', true),
                ),
            ],
            'sync_profile_permissions' => ['sometimes', 'boolean'],
            'permission_overrides' => ['sometimes', 'array'],
            'permission_overrides.*.permission_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('permissions', 'id'),
            ],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 12 caractères.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'professional_profile_id.required' => 'Choisissez le profil métier de ce compte.',
            'professional_profile_id.exists' => 'Le profil métier choisi ne correspond pas au rôle sélectionné.',
        ];
    }
}
