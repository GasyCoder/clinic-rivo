<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\StoreMedicalDischargeRequest;
use App\Models\HospitalStay;

/**
 * ADR-113 — la sortie d'hospitalisation reprend exactement les règles de la
 * sortie médicale (types, décès, transfert, ADR-035 et ADR-107). Seule
 * l'autorisation change : elle porte sur un séjour en cours au lieu d'une
 * consultation active. Le diagnostic final y est toujours exigé : un séjour
 * hospitalier n'est jamais un passage paraclinique seul (ADR-094).
 */
class DischargeHospitalStayRequest extends StoreMedicalDischargeRequest
{
    public function authorize(): bool
    {
        $stay = $this->route('hospitalStay');

        return $stay instanceof HospitalStay
            && $stay->isActive()
            && ! $stay->episode->medicalDischarge()->exists()
            && (bool) $this->user()?->can('medical_discharge.create');
    }
}
