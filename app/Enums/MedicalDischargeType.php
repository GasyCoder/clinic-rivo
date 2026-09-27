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

    /**
     * ADR-203 — les types proposés en consultation.
     *
     * Le transfert se décide par la conduite « Référence / Transfert » (module
     * Transferts, ADR-114) : le proposer aussi ici le faisait choisir deux fois,
     * avec deux suites différentes. Le cas reste lisible sur les sorties déjà
     * prononcées.
     *
     * @return list<self>
     */
    public static function forConsultation(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type !== self::Transfer));
    }
}
