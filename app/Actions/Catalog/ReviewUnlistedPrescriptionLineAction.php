<?php

namespace App\Actions\Catalog;

use App\Enums\PrescriptionLineReviewStatus;
use App\Models\PrescriptionLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Closes the review of a medicine a doctor added manually to a prescription
 * because it was missing from the Pharmacy catalog (ADR-024 keeps catalog
 * creation Super-Admin-default; this reuses `catalog.items.create` rather
 * than inventing a dedicated permission). This only records the outcome —
 * it never creates a Medicine/CatalogItem or stock row itself, since that
 * still requires Pharmacy lot data this MVP does not collect.
 */
class ReviewUnlistedPrescriptionLineAction
{
    public function execute(PrescriptionLine $line, string $note, User $actor): PrescriptionLine
    {
        if ($actor->cannot('catalog.items.create')) {
            throw new AuthorizationException('Vous ne pouvez pas traiter une demande de médicament hors référentiel.');
        }

        if (! $line->is_manual_entry) {
            throw ValidationException::withMessages([
                'line' => 'Cette ligne d’ordonnance est déjà reliée au référentiel Pharmacie.',
            ]);
        }

        if ($line->catalog_review_status === PrescriptionLineReviewStatus::Resolved) {
            throw ValidationException::withMessages([
                'line' => 'Cette demande a déjà été traitée.',
            ]);
        }

        $line->update([
            'catalog_review_status' => PrescriptionLineReviewStatus::Resolved,
            'catalog_reviewed_by' => $actor->getKey(),
            'catalog_reviewed_at' => now(),
            'catalog_review_note' => trim($note),
        ]);

        return $line;
    }
}
