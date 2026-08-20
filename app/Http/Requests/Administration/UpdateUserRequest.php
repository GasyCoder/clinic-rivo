<?php

namespace App\Http\Requests\Administration;

use App\Support\SecurePassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        return ($this->user()?->can('users.update') ?? false)
            && ($this->user()?->can('roles.assign') ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => ['nullable', 'confirmed', SecurePassword::rule()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
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
        ];
    }
}
