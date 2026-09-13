<?php

namespace App\Http\Requests\Medicine;

use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A draft is unvalidated typing: a half-written diagnosis or an incomplete
 * prescription line is exactly what must survive a reload. The payload is
 * bounded and its sections whitelisted, but the values themselves are not
 * clinically validated here — that happens when the doctor really saves.
 */
class SaveConsultationDraftRequest extends FormRequest
{
    /** Wizard forms whose typing is worth keeping; anything else is dropped. */
    public const ALLOWED_SECTIONS = [
        'consultation', 'interview', 'clinical_exam', 'diagnosis', 'prescription', 'care_order',
        'lab_request', 'imaging_request', 'referral', 'surgical_referral',
        'discharge',
    ];

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation) {
            return false;
        }

        // Same right as writing the consultation itself, and only while it
        // is actually being handled.
        return $orientation->status === EpisodeOrientationStatus::InProgress
            && $this->user()?->can('consultations.update') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
        ];
    }

    /**
     * Whitelisted, size-bounded payload. Never trusted as clinical data.
     *
     * @return array<string, mixed>
     */
    public function draftPayload(): array
    {
        // input(), not validated(): Laravel's nested-rule extraction would
        // drop every key we did not name explicitly, and a draft's shape is
        // the form's, not ours to enumerate.
        $payload = collect($this->input('payload', []))
            ->only(self::ALLOWED_SECTIONS)
            ->filter(fn ($section) => is_array($section))
            ->all();

        // A draft that no longer fits is not worth half-storing: what is on
        // screen stays, and the real save remains available.
        return mb_strlen(json_encode($payload) ?: '') > 128_000 ? [] : $payload;
    }
}
