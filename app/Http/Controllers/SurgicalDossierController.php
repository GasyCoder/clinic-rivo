<?php

namespace App\Http\Controllers;

use App\Models\SurgicalRequest;
use App\Support\Documents\SurgicalDossierSheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-172 — le « Dossier chirurgical » imprimable, généré depuis le dossier du
 * bloc et le dossier d'anesthésie. Il lit, il n'écrit rien.
 *
 * La route s'ouvre avec `surgery.view` **ou** `anesthesia.view` (capacité
 * `view-surgical-dossier`) ; chaque feuille est ensuite gardée par le droit
 * qui possède sa donnée, dans `SurgicalDossierSheet`.
 */
class SurgicalDossierController extends Controller
{
    public function print(Request $request, SurgicalRequest $surgicalRequest, SurgicalDossierSheet $sheet): Response
    {
        $only = $request->query('feuille');
        $only = is_string($only) && in_array($only, SurgicalDossierSheet::SHEETS, true) ? $only : null;

        return Inertia::render('Surgery/DossierPrint', [
            'dossier' => $sheet->present($surgicalRequest, $request->user(), $only),
            // Le bouton « Retour » ramène là d'où l'on vient : le bloc, ou l'anesthésie.
            'back' => $request->query('from') === 'anesthesia' && $request->user()->can('anesthesia.view')
                ? ['href' => "/anesthesia/{$surgicalRequest->uuid}", 'label' => 'Retour à l’anesthésie']
                : ['href' => "/surgery/{$surgicalRequest->uuid}", 'label' => 'Retour au dossier du bloc'],
        ]);
    }
}
