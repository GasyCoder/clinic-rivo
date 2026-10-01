<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Enums\HrStructureKind;
use App\Models\HrReferenceValue;
use App\Models\ProfessionalProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ADR-188 — un département ou une fonction, saisi depuis son module.
 *
 * Le type ne vient jamais du navigateur : c'est l'adresse (« /departments »,
 * « /job-titles ») qui le fixe. Le code, s'il est laissé vide, est tiré du
 * libellé — la même règle que le modèle, écrite ici pour que son unicité soit
 * vérifiée avant l'écriture plutôt que par une erreur SQL.
 */
class HrStructureRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $label = is_string($this->input('label')) ? Str::squish($this->input('label')) : $this->input('label');
        $code = is_string($this->input('code')) ? Str::squish($this->input('code')) : $this->input('code');

        if (blank($code) && is_string($label) && $label !== '') {
            $code = $label;
        }

        $this->merge([
            'label' => $label,
            'code' => is_string($code)
                ? Str::of($code)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(80, '')->toString()
                : $code,
        ]);
    }

    public function authorize(): bool
    {
        $reference = $this->route('reference');

        return $reference instanceof HrReferenceValue
            ? ($this->user()?->can('update', $reference) ?? false)
            : ($this->user()?->can('create', HrReferenceValue::class) ?? false);
    }

    public function rules(): array
    {
        $type = $this->referenceType();
        $reference = $this->route('reference');
        $ignore = $reference instanceof HrReferenceValue ? $reference : null;

        return [
            'label' => [
                'required', 'string', 'max:255',
                Rule::unique('hr_reference_values', 'label')->where('type', $type->value)->ignore($ignore),
            ],
            'code' => [
                'required', 'string', 'max:80',
                Rule::unique('hr_reference_values', 'code')->where('type', $type->value)->ignore($ignore),
            ],
            'active' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // ADR-194 — les départements où cette fonction existe. Omettre la
            // clé laisse les liens tels quels ; une liste vide les retire tous.
            'department_uuids' => [Rule::excludeUnless($type === HrReferenceType::JobTitle), 'sometimes', 'array', 'max:100'],
            'department_uuids.*' => [
                Rule::excludeUnless($type === HrReferenceType::JobTitle), 'uuid', 'distinct',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::Department->value)
                    ->whereNull('deleted_at'),
            ],
            // ADR-199 — le rôle (et le profil) que cette fonction propose au compte de
            // celui qui l'exerce. Omettre la clé laisse le réglage tel quel ; `null`
            // dit qu'elle n'en propose aucun. Jamais SUPER_ADMIN (ADR-027).
            'account_role_code' => [
                Rule::excludeUnless($type === HrReferenceType::JobTitle), 'sometimes', 'nullable', 'string',
                Rule::exists('roles', 'code')->whereNull('deleted_at')->whereNot('code', 'SUPER_ADMIN'),
            ],
            'account_profile_code' => [
                Rule::excludeUnless($type === HrReferenceType::JobTitle), 'sometimes', 'nullable', 'string',
            ],
            // ADR-221 — ouvre droit aux avantages et primes. Omis = inchangé.
            'benefits_eligible' => [Rule::excludeUnless($type === HrReferenceType::JobTitle), 'sometimes', 'boolean'],
        ];
    }

    /** Un profil n'existe que dans un rôle : il doit appartenir à celui qui est proposé. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->referenceType() !== HrReferenceType::JobTitle || ! $this->filled('account_profile_code')) {
                return;
            }

            $role = $this->input('account_role_code');
            $belongs = is_string($role) && ProfessionalProfile::query()->active()
                ->where('code', $this->input('account_profile_code'))
                ->whereHas('role', fn ($query) => $query->where('code', $role))
                ->exists();

            if (! $belongs) {
                $validator->errors()->add('account_profile_code', 'Ce profil métier n’appartient pas au rôle proposé.');
            }
        }];
    }

    public function messages(): array
    {
        $noun = $this->referenceType() === HrReferenceType::Department ? 'un département' : 'une fonction';

        return [
            'label.unique' => "Ce libellé est déjà utilisé par {$noun}, peut-être archivé : restaurez-le plutôt que de le recréer.",
            'code.unique' => "Ce code est déjà utilisé par {$noun}, peut-être archivé.",
            'code.required' => 'Le code est obligatoire : saisissez un libellé pour le générer.',
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'libellé', 'code' => 'code', 'position' => 'ordre',
            'department_uuids' => 'départements', 'department_uuids.*' => 'département',
            'account_role_code' => 'rôle proposé', 'account_profile_code' => 'profil proposé',
        ];
    }

    public function referenceType(): HrReferenceType
    {
        return HrStructureKind::fromRoute($this->route())->referenceType();
    }
}
