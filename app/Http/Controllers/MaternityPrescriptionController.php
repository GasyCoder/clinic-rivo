<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Enums\CatalogModule;
use App\Enums\PrescriptionStatus;
use App\Http\Requests\Maternity\CancelMaternityPrescriptionRequest;
use App\Http\Requests\Maternity\StoreMaternityPrescriptionRequest;
use App\Models\EpisodeOrientation;
use App\Models\Prescription;
use App\Support\Medicine\PrescriptionDocument;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-205 — la sage-femme prescrit depuis le dossier Maternité.
 *
 * Mêmes gestes qu'en consultation et au séjour, par les mêmes actions : écrire
 * l'ordonnance (réservation FEFO, demande de délivrance), la retirer avec un
 * motif, l'imprimer. La Maternité n'encaisse rien : la patiente règle à la
 * Caisse, puis la Pharmacie délivre (ADR-012, ADR-049).
 */
class MaternityPrescriptionController extends Controller
{
    public function store(StoreMaternityPrescriptionRequest $request, EpisodeOrientation $episodeOrientation, CreatePrescriptionAction $action): RedirectResponse
    {
        $action->executeForMaternity($episodeOrientation, $request->validated('lines'), $request->user());

        return back()->with('status', 'Ordonnance transmise à la Pharmacie. La patiente règle à la Caisse avant la délivrance.');
    }

    public function cancel(
        CancelMaternityPrescriptionRequest $request,
        EpisodeOrientation $episodeOrientation,
        Prescription $prescription,
        CancelPrescriptionAction $action,
    ): RedirectResponse {
        $action->execute($prescription, $request->validated('reason'), $request->user());

        return back()
            ->with('status', 'Ordonnance retirée. Le stock réservé a été libéré.')
            ->with('status_type', 'warning');
    }

    public function print(EpisodeOrientation $episodeOrientation, Prescription $prescription): Response
    {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Maternity, 404);

        $record = $episodeOrientation->episode?->maternityRecord;

        abort_unless($record !== null && $prescription->maternity_record_id === $record->getKey(), 404);
        abort_unless($prescription->status === PrescriptionStatus::Active, 409, 'Seule une ordonnance active peut être imprimée.');

        return Inertia::render('Medicine/PrescriptionPrint', PrescriptionDocument::printPage(
            $prescription,
            $episodeOrientation->episode,
            "/maternity/orientations/{$episodeOrientation->uuid}#ordonnance",
        ));
    }
}
