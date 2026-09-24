<?php

namespace App\Enums;

/**
 * The usual clinical nature of a priced service selected at Reception.
 *
 * This is deliberately independent from CatalogModule: two services owned
 * by Medicine (for example a general consultation and an ultrasound) may
 * have different entry paths.
 *
 * ADR-177 — it no longer decides who may see the patient. A passage is
 * visible to every authorised clinical service whatever its designations
 * (`ActiveEpisodeBoard`), and the Reception's next step is a separate,
 * optional suggestion. What the mode still drives:
 *
 * ```text
 * LABORATORY_DIRECT / SURGERY_DIRECT  the real request created at arrival
 *                                     (a LabRequest, a SurgicalRequest)
 * CARE_ONLY / CARE_THEN_MEDICINE      the outcome pre-selected at the end of
 *                                     Soins (CareWorkflow, ADR-166)
 * MEDICINE_DIRECT + IMAGING/LAB       a paraclinical-only visit (ADR-076/094)
 * ```
 */
enum ReceptionRoutingMode: string
{
    case MedicineDirect = 'MEDICINE_DIRECT';
    case CareThenMedicine = 'CARE_THEN_MEDICINE';
    case CareOnly = 'CARE_ONLY';
    case LaboratoryDirect = 'LABORATORY_DIRECT';
    case MaternityDirect = 'MATERNITY_DIRECT';
    // ADR-159 — un acte du bloc peut être la raison même de la venue : la
    // Réception l'inscrit alors comme elle inscrit une analyse ou un acte de
    // Maternité (ADR-068). Le bloc reçoit une demande, jamais un dossier
    // qu'il aurait ouvert lui-même.
    case SurgeryDirect = 'SURGERY_DIRECT';

    public function label(): string
    {
        return match ($this) {
            self::MedicineDirect => 'Médecine directement',
            self::CareThenMedicine => 'Soins puis Médecine',
            self::CareOnly => 'Soins uniquement',
            self::LaboratoryDirect => 'Laboratoire directement',
            self::MaternityDirect => 'Maternité directement',
            self::SurgeryDirect => 'Chirurgie directement',
        };
    }

    /**
     * The module a catalog item of this mode must belong to — a consistency
     * check of the referential (an analysis routed to the Laboratory is a
     * Laboratory item), never a queue.
     */
    public function directDestination(): ?CatalogModule
    {
        return match ($this) {
            self::LaboratoryDirect => CatalogModule::Laboratory,
            self::MaternityDirect => CatalogModule::Maternity,
            self::SurgeryDirect => CatalogModule::Surgery,
            default => null,
        };
    }

    /**
     * ADR-177 — the modes whose selection at Reception still creates a real
     * technical request that a service must carry out: the analyses of a
     * `LabRequest` (ADR-068) and the act of a `SurgicalRequest` (ADR-159).
     * Maternity is deliberately absent: its orientation only made the passage
     * visible, and visibility no longer comes from the designation.
     */
    public function technicalRequestModule(): ?CatalogModule
    {
        return match ($this) {
            self::LaboratoryDirect => CatalogModule::Laboratory,
            self::SurgeryDirect => CatalogModule::Surgery,
            default => null,
        };
    }
}
