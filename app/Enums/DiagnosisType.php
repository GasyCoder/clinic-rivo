<?php

namespace App\Enums;

/**
 * Client CDCF §6.1 separates "enregistrer les hypothèses diagnostiques"
 * (plural, working diagnoses) from "enregistrer le diagnostic" (singular,
 * final) as two distinct médecin actions — hence a `type` column rather
 * than collapsing both into one free-text field on Consultation.
 */
enum DiagnosisType: string
{
    case Hypothesis = 'HYPOTHESIS';
    case Final = 'FINAL';
}
