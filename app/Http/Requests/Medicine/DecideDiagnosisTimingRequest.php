<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * « Le diagnostic peut-il être posé maintenant ? », à l'étape qui le pose.
 *
 * La question vivait dans l'Examen clinique (ADR-080). Un passage venu
 * seulement pour un ECG ou une échographie n'y passe jamais — cette étape
 * lui est sans objet (ADR-076) — si bien qu'il ne pouvait ni y répondre ni
 * expliquer pourquoi sa consultation restait ouverte. Elle rejoint donc
 * « Décision & clôture », seule étape que tout patient atteint (ADR-095).
 *
 * L'endpoint est distinct de l'enregistrement de l'examen clinique, pour la
 * même raison que celui de l'ADR-079 : répondre à une question ne doit
 * jamais réécrire l'état général, la conscience ou les appareils examinés.
 */
class DecideDiagnosisTimingRequest extends FormRequest
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
            // Obligatoire ici, contrairement à l'examen : cet endpoint
            // n'existe que pour répondre, un appel vide ne dirait rien.
            'ready' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ready.required' => 'Indiquez si le diagnostic peut être posé maintenant.',
        ];
    }
}
