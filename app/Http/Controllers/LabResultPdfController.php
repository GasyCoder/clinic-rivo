<?php

namespace App\Http\Controllers;

use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Services\Laboratory\LabResultAccess;
use App\Services\Laboratory\LabResultReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-218 — le compte rendu de résultats d'analyses en PDF, produit par le
 * serveur (dompdf) comme le laboratoire de la clinique le remettait.
 *
 * Deux lecteurs, les mêmes règles que leurs pages :
 *
 *   - le laboratoire (`/laboratory/requests/{uuid}/resultats.pdf`) : tout ce qui
 *     est rendu, un résultat pas encore envoyé marqué provisoire ;
 *   - le médecin (`/resultats-analyses/{uuid}/pdf`) : seulement ce qui lui a été
 *     envoyé (ADR-216), et rien d'une demande adressée à un confrère avant une
 *     ouverture confirmée et tracée.
 *
 * `?telecharger=1` le donne en pièce jointe ; sinon il s'affiche dans la page.
 */
class LabResultPdfController extends Controller
{
    public function laboratory(Request $request, LabRequest $labRequest, LabResultReport $report, LabResultAccess $access): Response
    {
        $labRequest->load('items.results');
        abort_if($access->sealed($labRequest, $request->user()), 403, 'Ces résultats sont adressés à un confrère : ouvrez-les d’abord depuis leur page.');

        // Tout ce qui porte un résultat, envoyé ou non : ce qui ne l'est pas est marqué provisoire.
        $items = $labRequest->items->filter(fn (LabRequestItem $item) => $item->resulted_at !== null
            || $item->results->contains(fn ($result) => ! $result->isBlank()));
        abort_if($items->isEmpty(), 404, 'Aucun résultat saisi pour cette demande.');

        return $this->respond($request, $labRequest, $report, $report->compose($labRequest, $items));
    }

    public function physician(Request $request, LabRequest $labRequest, LabResultReport $report, LabResultAccess $access): Response
    {
        $labRequest->load('items');
        $user = $request->user();
        abort_if($access->sealed($labRequest, $user), 403, 'Ces résultats sont adressés à un confrère : confirmez d’abord leur ouverture.');

        // Le laboratoire lit tout ce qu'il a rendu ; un médecin, ce qui lui a été envoyé.
        $viewer = $user->can('laboratory_results.create') ? null : $user;
        $items = $labRequest->items->filter(fn (LabRequestItem $item) => $item->isDelivered());
        abort_if($items->isEmpty(), 404, 'Aucun résultat ne vous a encore été envoyé pour cette demande.');

        return $this->respond($request, $labRequest, $report, $report->compose($labRequest, $items, $viewer));
    }

    private function respond(Request $request, LabRequest $labRequest, LabResultReport $report, array $composed): Response
    {
        $filename = $report->filename($labRequest);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return response($report->render($composed), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
