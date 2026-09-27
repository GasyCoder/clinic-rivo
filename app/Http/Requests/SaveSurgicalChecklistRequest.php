<?php

namespace App\Http\Requests;

use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Models\SurgicalRequest;
use App\Support\SurgicalSafetyChecklistItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-170 — cocher un temps de la checklist, et éventuellement le confirmer
 * pour son propre rôle.
 *
 * Le rôle que l'on peut confirmer dépend du dossier, pas seulement du compte :
 * ce contrôle appartient à `SaveSurgicalChecklistAction`, qui le fait sous
 * verrou. Ici, seule la forme.
 */
class SaveSurgicalChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('surgicalRequest');

        return $case instanceof SurgicalRequest
            && $this->user()?->can('saveChecklist', $case) === true;
    }

    public function rules(): array
    {
        $phase = $this->phase();
        $keys = SurgicalSafetyChecklistItems::keys($phase);
        $roles = array_map(
            fn (SurgicalChecklistRole $role) => $role->value,
            $phase->requiredRoles(),
        );

        return [
            // Une clé inconnue n'est pas « rejetée en silence » : elle est
            // nommée. Les items sont un référentiel relu (ADR-170), pas un
            // stockage libre.
            'items' => ['array', function (string $attribute, $value, $fail) use ($keys) {
                foreach (array_keys((array) $value) as $key) {
                    if (! in_array($key, $keys, true)) {
                        $fail("Le point « {$key} » n’appartient pas à ce temps de la checklist.");
                    }
                }
            }],
            'items.*' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm_as' => ['nullable', 'string', Rule::in($roles)],
        ];
    }

    public function phase(): SurgicalChecklistPhase
    {
        $phase = $this->route('phase');

        return $phase instanceof SurgicalChecklistPhase
            ? $phase
            : SurgicalChecklistPhase::from((string) $phase);
    }
}
