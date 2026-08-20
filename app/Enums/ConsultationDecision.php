<?php

namespace App\Enums;

/**
 * "Le médecin consulte le dossier et peut décider" — client CDCF §3.1,
 * transcribed as-is (11 options), not invented. Data capture only: acting
 * on HOSPITALIZATION/SURGERY/MATERNITY_REFERRAL/PEDIATRICS_REFERRAL/
 * EXTERNAL_TRANSFER/LABORATORY_TESTS still requires the module that owns
 * that workflow (Hospitalisation, Chirurgie, Maternité, Transfert,
 * Laboratoire — none built yet).
 */
enum ConsultationDecision: string
{
    case SimpleTreatment = 'SIMPLE_TREATMENT';
    case MedicationPrescription = 'MEDICATION_PRESCRIPTION';
    case NursingCare = 'NURSING_CARE';
    case LaboratoryTests = 'LABORATORY_TESTS';
    case Imaging = 'IMAGING';
    case Hospitalization = 'HOSPITALIZATION';
    case MaternityReferral = 'MATERNITY_REFERRAL';
    case PediatricsReferral = 'PEDIATRICS_REFERRAL';
    case Surgery = 'SURGERY';
    case ExternalTransfer = 'EXTERNAL_TRANSFER';
    case Discharge = 'DISCHARGE';
}
