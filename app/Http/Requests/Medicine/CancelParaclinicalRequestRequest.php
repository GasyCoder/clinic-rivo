<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Retrait d'une demande d'examen unique.
 *
 * La demande est désignée par son UUID et sa famille — `lab` ou `imaging` —
 * plutôt que par un identifiant numérique : les deux tables ont leurs
 * propres séquences, et un `id` seul serait ambigu (ADR-005 réserve de toute
 * façon l'UUID aux identifiants exposés).
 */
class CancelParaclinicalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('consultations.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['lab', 'imaging'])],
            'uuid' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.in' => 'Type de demande inconnu.',
            'uuid.required' => 'Indiquez la demande à retirer.',
        ];
    }

    /**
     * La demande visée, cherchée **dans cette consultation** : un UUID
     * valide appartenant à un autre passage ne doit pas être atteignable.
     *
     * @throws ValidationException
     */
    public function target(Consultation $consultation): LabRequest|ImagingRequest
    {
        $relation = $this->validated('kind') === 'lab' ? 'labRequests' : 'imagingRequests';

        $target = $consultation->{$relation}()
            ->where('uuid', $this->validated('uuid'))
            ->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'request' => 'Cette demande est introuvable pour cette consultation.',
            ]);
        }

        return $target;
    }
}
