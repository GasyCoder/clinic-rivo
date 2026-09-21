<?php

namespace App\Enums;

/**
 * ADR-161 — comment un séjour s'est terminé.
 *
 * Lu sur la sortie médicale quand il y en a une ; posé par le départ constaté
 * quand le patient est transféré vers un autre établissement, car ce départ
 * ne porte aucune sortie médicale.
 */
enum HospitalStayEndReason: string
{
    case Home = 'HOME';
    case Transfer = 'TRANSFER';
    case AgainstAdvice = 'AGAINST_ADVICE';
    case DecisionRefusal = 'DECISION_REFUSAL';
    case Deceased = 'DECEASED';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Sortie à domicile',
            self::Transfer => 'Transféré vers un autre établissement',
            self::AgainstAdvice => 'Sortie à la demande du patient',
            self::DecisionRefusal => 'Refus de la décision médicale',
            self::Deceased => 'Décès',
        };
    }

    public static function fromDischargeType(MedicalDischargeType $type): self
    {
        return match ($type) {
            MedicalDischargeType::Normal => self::Home,
            MedicalDischargeType::Transfer => self::Transfer,
            MedicalDischargeType::AtPatientRequest => self::AgainstAdvice,
            MedicalDischargeType::MedicalDecisionRefusal => self::DecisionRefusal,
            MedicalDischargeType::Deceased => self::Deceased,
        };
    }
}
