<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\MaternityEncounterType;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-204 — choisir le parcours ouvre le dossier : le droit de l'écrire est exigé. */
class StartMaternityEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation || $orientation->destination_module !== CatalogModule::Maternity) {
            return false;
        }

        $exists = $orientation->episode()->whereHas('maternityRecord')->exists();

        return (bool) $this->user()?->can($exists ? 'maternity.update' : 'maternity.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'encounter_type' => ['required', Rule::enum(MaternityEncounterType::class)],
        ];
    }

    public function encounterType(): MaternityEncounterType
    {
        return MaternityEncounterType::from($this->validated('encounter_type'));
    }
}
