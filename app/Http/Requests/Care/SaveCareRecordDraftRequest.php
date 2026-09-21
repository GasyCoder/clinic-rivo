<?php

namespace App\Http\Requests\Care;

use App\Models\EpisodeOrientation;
use App\Support\CareHandlerGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A draft is unvalidated typing: an incomplete blood pressure like "170/" is
 * exactly what must survive a reload. So the payload is bounded and its keys
 * whitelisted, but the values themselves are not clinically validated here —
 * that happens when the nurse really saves the record.
 */
class SaveCareRecordDraftRequest extends FormRequest
{
    /** Fields the worksheet can hold; anything else is dropped. */
    public const ALLOWED_KEYS = [
        'blood_group', 'blood_pressure_systolic', 'blood_pressure_diastolic',
        'heart_rate', 'spo2', 'temperature_celsius', 'known_diabetes',
        'diabetes_note', 'height_cm', 'weight_kg', 'smoker', 'alcohol',
        'allergy_note', 'allergy_uuids', 'allergen_reference_uuids',
        'new_allergies', 'diagnostic_note', 'transmission_reason',
        'no_procedure_reason', 'procedures', 'consumables', 'consumable_notes',
        'care_outcome', 'care_outcome_reason',
    ];

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation) {
            return false;
        }

        $orientation->loadMissing('episode.careRecord');

        // Même droit que l'écriture de la fiche, et tant qu'elle reste
        // corrigeable — donc aussi après le transfert vers Médecine.
        return CareHandlerGuard::isEditable($orientation)
            && $this->user()?->can($orientation->episode->careRecord ? 'care.update' : 'care.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'payload.procedures' => ['sometimes', 'array', 'max:30'],
            'payload.consumables' => ['sometimes', 'array', 'max:30'],
            'payload.allergy_uuids' => ['sometimes', 'array', 'max:20'],
            'payload.allergen_reference_uuids' => ['sometimes', 'array', 'max:20'],
            'payload.new_allergies' => ['sometimes', 'array', 'max:10'],
        ];
    }

    /**
     * Whitelisted, size-bounded payload. Never trusted as clinical data.
     *
     * @return array<string, mixed>
     */
    public function draftPayload(): array
    {
        // input(), not validated(): declaring nested rules such as
        // `payload.procedures` makes validated() return only those explicit
        // paths, which would silently drop every scalar field. Validation
        // has already run; the whitelist below is what bounds the payload.
        $payload = collect($this->input('payload', []))
            ->only(self::ALLOWED_KEYS)
            ->all();

        // A draft that no longer fits is not worth half-storing: the nurse
        // keeps what is on screen and the real save remains available.
        return mb_strlen(json_encode($payload) ?: '') > 64_000 ? [] : $payload;
    }
}
