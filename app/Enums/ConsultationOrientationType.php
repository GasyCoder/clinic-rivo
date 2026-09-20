<?php

namespace App\Enums;

/**
 * The conduite à tenir: what happens to this patient after the encounter.
 *
 * This is a business datum, not a screen (ADR-084). The doctor may settle it
 * as soon as the interview or the examination makes it obvious, and the
 * matching request form opens there and then — no "Décision" step to reach
 * before being allowed to say what was already decided.
 *
 * Six values only. They are the six destinations the wizard already offered,
 * not a new vocabulary: `ConsultationDecision` keeps recording the same
 * choice for everything that reads a consultation's decision today.
 */
enum ConsultationOrientationType: string
{
    case Discharge = 'DISCHARGE';
    case Hospitalization = 'HOSPITALIZATION';
    case Surgery = 'SURGERY';
    case Maternity = 'MATERNITY';
    case Pediatrics = 'PEDIATRICS';
    case Referral = 'REFERRAL';
    /**
     * ADR-149 — la conclusion normale d'une visite de service : le patient
     * reste dans son lit. Aucune des six autres ne convenait pendant un
     * séjour, et la clôture en exige une transmise (ADR-084) : une visite
     * était donc impossible à clôturer.
     */
    case ContinuedHospitalization = 'CONTINUED_HOSPITALIZATION';

    public function label(): string
    {
        return match ($this) {
            self::Discharge => 'Sortie médicale',
            self::Hospitalization => 'Hospitalisation',
            self::Surgery => 'Chirurgie',
            self::Maternity => 'Maternité',
            self::Pediatrics => 'Pédiatrie',
            self::Referral => 'Référence / Transfert',
            self::ContinuedHospitalization => 'Poursuite de l’hospitalisation',
        };
    }

    /** What the doctor is about to fill in, said once at the top of the form. */
    public function formTitle(): string
    {
        return match ($this) {
            self::Discharge => 'Sortie médicale',
            self::Hospitalization => 'Demande d’hospitalisation',
            self::Surgery => 'Demande de chirurgie',
            self::Maternity => 'Orientation Maternité',
            self::Pediatrics => 'Orientation Pédiatrie',
            self::Referral => 'Référence / Transfert',
            self::ContinuedHospitalization => 'Poursuite de l’hospitalisation',
        };
    }

    /**
     * The permission that really gates this destination — re-checked
     * server-side on every write. Vue filtering is ergonomics only
     * (ADR-075, unchanged on this point).
     */
    public function permission(): string
    {
        return match ($this) {
            self::Discharge => 'medical_discharge.create',
            self::Hospitalization => 'hospitalization.request',
            self::Surgery => 'surgery.request',
            self::Maternity => 'maternity.request',
            self::Pediatrics => 'pediatrics.request',
            self::Referral => 'transfer.request',
            // Rien n'est demandé à personne : le patient reste où il est.
            // Écrire sa consultation suffit donc à le décider.
            self::ContinuedHospitalization => 'consultations.update',
        };
    }

    /**
     * Where the episode goes next. A discharge goes nowhere: it ends the
     * medical pathway instead of handing the patient to another service,
     * which is why it is the one type with no destination module.
     */
    public function destinationModule(): ?CatalogModule
    {
        return match ($this) {
            self::Discharge => null,
            self::Hospitalization => CatalogModule::Hospitalization,
            self::Surgery => CatalogModule::Surgery,
            self::Maternity => CatalogModule::Maternity,
            self::Pediatrics => CatalogModule::Pediatrics,
            self::Referral => CatalogModule::Transfer,
            // Aucune orientation nouvelle : celle du séjour est déjà ouverte,
            // et le patient n'est transmis à personne (ADR-113).
            self::ContinuedHospitalization => null,
        };
    }

    /**
     * `Consultation.decision` predates this enum and is read by the passage
     * detail page, the episode API and older records. Keeping it written
     * means no reader has to learn a second vocabulary and no historical
     * decision becomes unreadable (§32).
     */
    public function legacyDecision(): ConsultationDecision
    {
        return match ($this) {
            self::Discharge => ConsultationDecision::Discharge,
            self::Hospitalization => ConsultationDecision::Hospitalization,
            self::Surgery => ConsultationDecision::Surgery,
            self::Maternity => ConsultationDecision::MaternityReferral,
            self::Pediatrics => ConsultationDecision::PediatricsReferral,
            self::Referral => ConsultationDecision::ExternalTransfer,
            // « Hospitalisation » dit vrai du passage : il l'est, et le reste.
            // Aucun lecteur n'a de second vocabulaire à apprendre (§32).
            self::ContinuedHospitalization => ConsultationDecision::Hospitalization,
        };
    }

    /** The reverse reading, for consultations recorded before ADR-084. */
    public static function fromLegacyDecision(?ConsultationDecision $decision): ?self
    {
        return match ($decision) {
            ConsultationDecision::Discharge => self::Discharge,
            ConsultationDecision::Hospitalization => self::Hospitalization,
            ConsultationDecision::Surgery => self::Surgery,
            ConsultationDecision::MaternityReferral => self::Maternity,
            ConsultationDecision::PediatricsReferral => self::Pediatrics,
            ConsultationDecision::ExternalTransfer => self::Referral,
            default => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
