<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\HospitalCareLevel;
use App\Models\HospitalStay;
use App\Support\Hospitalization\BedDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-161 — une mutation interne : service, lit ou niveau de soins.
 *
 * ADR-164 — un site qui a configuré ses lits choisit un lit du référentiel ;
 * le service et le niveau de soins en découlent. Les saisir à la main est
 * refusé avec un message, jamais ignoré en silence : sinon un lit occupé
 * pourrait être attribué sans contrôle.
 */
class MoveHospitalStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $stay = $this->route('hospitalStay');

        return $stay instanceof HospitalStay
            && $stay->isActive()
            && (bool) $this->user()?->can('hospitalization.update');
    }

    public function rules(): array
    {
        if (app(BedDirectory::class)->configured()) {
            return [
                'hospital_bed_uuid' => ['required', 'uuid'],
                'service' => ['prohibited'],
                'room_bed' => ['prohibited'],
                'care_level' => ['prohibited'],
                'reason' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return [
            'hospital_bed_uuid' => ['prohibited'],
            'service' => ['nullable', 'string', 'max:150'],
            'room_bed' => ['nullable', 'string', 'max:100'],
            'care_level' => ['required', Rule::enum(HospitalCareLevel::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'hospital_bed_uuid.prohibited' => 'Aucun lit n’est encore configuré pour ce site : notez la chambre à la main.',
            'hospital_bed_uuid.required' => 'Choisissez le lit où installer le patient.',
            'service.prohibited' => 'Le service ne se saisit plus à la main : c’est celui du lit choisi.',
            'room_bed.prohibited' => 'Les lits de ce site sont maintenant configurés : la chambre ne se saisit plus à la main. Utilisez « Attribuer un lit » ou « Changer de lit » (actualisez la page si ces boutons n’apparaissent pas).',
            'care_level.prohibited' => 'Le niveau de soins ne se choisit plus à la main : c’est celui du service du lit choisi.',
        ];
    }
}
