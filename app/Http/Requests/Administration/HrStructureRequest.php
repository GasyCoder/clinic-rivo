<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Enums\HrStructureKind;
use App\Models\HrReferenceValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        ];
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
        return ['label' => 'libellé', 'code' => 'code', 'position' => 'ordre'];
    }

    public function referenceType(): HrReferenceType
    {
        return HrStructureKind::fromRoute($this->route())->referenceType();
    }
}
