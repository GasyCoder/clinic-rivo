<?php

namespace App\Enums;

/**
 * Patient's progress through the site's reception/orientation/exit circuit.
 * Not specified by the CDC GitHub repo (§21 lists the administrative_status
 * column but gives no values) — aligned instead with the client's own
 * Cahier des Charges Fonctionnel (docs/Idée_client_pendant_reunion.pdf,
 * §34-36), which is more detailed here than the GitHub repo and is being
 * reconciled into it.
 *
 * The client's §36 "statuts recommandés" list also names three exit
 * sub-types tied to the patient's account balance — Sorti/payé comptant,
 * Sorti/dette validée, Sorti/évadé (§34.1.3-34.1.6) — deliberately not
 * modeled here yet: Facture/Caisse (which owns "solde") doesn't exist yet,
 * so DISCHARGED is a single terminal bucket until that module can split it
 * into those three states for real, per §34's own exit rules.
 *
 * Kept separate from EpisodeStatus (global) and from medical_status/
 * financial_status (owned by future modules), per §21's "le statut
 * médical, financier et administratif doit rester séparé" — matched here
 * by §29 Règle 2 and §33's Patient → Épisode → Prestations model.
 */
enum EpisodeAdministrativeStatus: string
{
    case PendingOrientation = 'PENDING_ORIENTATION';
    case Oriented = 'ORIENTED';
    case InCare = 'IN_CARE';
    case PendingSettlement = 'PENDING_SETTLEMENT';
    case Discharged = 'DISCHARGED';
}
