<?php

namespace App\Enums;

/**
 * Patient's progress through the site's reception/orientation/exit circuit.
 * Not specified by the CDC GitHub repo (§21 lists the administrative_status
 * column but gives no values) — aligned instead with the client's own
 * Cahier des Charges Fonctionnel (docs/Idée_client_pendant_reunion.pdf,
 * §34-36), reconciled into the CDC repo's §32.
 *
 * CDC §32's three exit sub-types — Sorti/payé comptant, Sorti/dette
 * validée, Sorti/évadé — were deliberately collapsed into a single
 * DISCHARGED bucket while Facture/Caisse did not exist: nothing could tell
 * them apart without a real balance. Caisse now exists, so §33.3 is
 * modelled for real (ADR-090) and those three states are distinct here.
 *
 * DISCHARGED is kept readable but is never written by any code path since
 * ADR-090: no row ever carried it (nothing wrote it before either), and
 * removing a case only to re-add it if one turns up would be the riskier
 * choice.
 *
 * Kept separate from EpisodeStatus (global) and from medical_status/
 * financial_status, per §21's "le statut médical, financier et
 * administratif doit rester séparé" — matched here by §29 Règle 2 and
 * §33's Patient → Épisode → Prestations model.
 */
enum EpisodeAdministrativeStatus: string
{
    case PendingOrientation = 'PENDING_ORIENTATION';
    case Oriented = 'ORIENTED';
    case InCare = 'IN_CARE';
    case PendingSettlement = 'PENDING_SETTLEMENT';
    /** @deprecated ADR-090 — legacy bucket, never written; read-only. */
    case Discharged = 'DISCHARGED';
    case DischargedPaid = 'DISCHARGED_PAID';
    case DischargedDebt = 'DISCHARGED_DEBT';
    case DischargedEscaped = 'DISCHARGED_ESCAPED';

    public function label(): string
    {
        return match ($this) {
            self::PendingOrientation => 'En attente d’orientation',
            self::Oriented => 'Orienté',
            self::InCare => 'En cours de soins',
            self::PendingSettlement => 'En attente de règlement',
            self::Discharged => 'Sorti',
            self::DischargedPaid => 'Sorti — payé comptant',
            self::DischargedDebt => 'Sorti — dette validée',
            self::DischargedEscaped => 'Sorti — évadé',
        };
    }

    /** True once the passage has left the site administratively. */
    public function isDischarged(): bool
    {
        return in_array($this, [
            self::Discharged,
            self::DischargedPaid,
            self::DischargedDebt,
            self::DischargedEscaped,
        ], true);
    }
}
