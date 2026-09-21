<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\MedicalDischargeType;
use App\Http\Requests\StoreMedicalDischargeRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-162 — la sortie médicale prononcée sur la page du séjour : les mêmes
 * champs et les mêmes règles que celle d'une consultation (ADR-107), sauf
 * deux différences qui tiennent au patient au lit.
 */
class DischargeHospitalStayRequest extends StoreMedicalDischargeRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('medical_discharge.create')
            && ! $this->route('hospitalStay')->episode->medicalDischarge()->exists();
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Un séjour n'est jamais un passage paraclinique seul (ADR-094) :
            // sa sortie porte toujours un diagnostic final.
            'final_diagnosis' => ['required', 'string', 'max:5000'],
            // ADR-161 — un transfert se demande, et se termine au départ.
            'type' => ['required', Rule::enum(MedicalDischargeType::class)->except([MedicalDischargeType::Transfer])],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'type.enum' => 'Un transfert se demande depuis le séjour (« Demander un transfert ») : le séjour se termine au départ du patient.',
        ];
    }
}
