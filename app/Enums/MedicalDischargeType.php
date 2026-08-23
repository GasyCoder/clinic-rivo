<?php

namespace App\Enums;

/** CDC §33.1 and the client discharge sheets supplied on 2026-08-23. */
enum MedicalDischargeType: string
{
    case Normal = 'NORMAL';
    case Transfer = 'TRANSFER';
    case AtPatientRequest = 'AT_PATIENT_REQUEST';
    case MedicalDecisionRefusal = 'MEDICAL_DECISION_REFUSAL';
    case Deceased = 'DECEASED';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Sortie normale',
            self::Transfer => 'Référence / transfert',
            self::AtPatientRequest => 'Sortie à la demande du patient',
            self::MedicalDecisionRefusal => 'Refus de la décision médicale',
            self::Deceased => 'Décès',
        };
    }

    public function medicalStatus(): EpisodeMedicalStatus
    {
        return match ($this) {
            self::Normal, self::AtPatientRequest, self::MedicalDecisionRefusal => EpisodeMedicalStatus::MedicallyDischarged,
            self::Transfer => EpisodeMedicalStatus::Transferred,
            self::Deceased => EpisodeMedicalStatus::Deceased,
        };
    }
}
