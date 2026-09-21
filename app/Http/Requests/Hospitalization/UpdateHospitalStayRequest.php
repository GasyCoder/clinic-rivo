<?php

namespace App\Http\Requests\Hospitalization;

use App\Support\Hospitalization\BedDirectory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-113 — le service et la chambre / le lit, en texte libre et facultatifs.
 *
 * ADR-164 — dès que le site a configuré ses lits, l'emplacement se choisit
 * dans le référentiel : un lit, et le service qui va avec.
 */
class UpdateHospitalStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hospitalization.update');
    }

    public function rules(): array
    {
        if (app(BedDirectory::class)->configured()) {
            return [
                'hospital_bed_uuid' => ['required', 'uuid'],
                'room_bed' => ['prohibited'],
                'service' => ['prohibited'],
            ];
        }

        return [
            'hospital_bed_uuid' => ['prohibited'],
            'room_bed' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'hospital_bed_uuid.prohibited' => 'Aucun lit n’est encore configuré pour ce site : notez la chambre à la main.',
            'hospital_bed_uuid.required' => 'Choisissez le lit du patient.',
            'room_bed.prohibited' => 'Les lits de ce site sont maintenant configurés : la chambre ne se saisit plus à la main. Utilisez « Attribuer un lit » ou « Changer de lit » (actualisez la page si ces boutons n’apparaissent pas).',
            'service.prohibited' => 'Le service ne se saisit plus à la main : c’est celui du lit choisi.',
        ];
    }
}
