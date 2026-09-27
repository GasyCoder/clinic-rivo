<?php

namespace App\Http\Controllers;

use App\Actions\Maternity\CreateNewbornPatientAction;
use App\Models\MaternityRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-177 — un bébé né à la clinique devient patient **depuis la Maternité**,
 * là où il est consigné.
 *
 * La Réception n'a plus de mode « Nouveau-né » : elle ne connaît que « Patient
 * existant » et « Nouveau patient » — un bébé né ailleurs est simplement un
 * nouveau patient. Les règles de la création sont inchangées et restent
 * celles de `CreateNewbornPatientAction` (ADR-144, ADR-146) : numéro dérivé de
 * celui de la mère, naissance jamais devinée, sexe exigé, geste idempotent.
 */
class MaternityNewbornController extends Controller
{
    public function store(
        Request $request,
        MaternityRecord $maternityRecord,
        string $newbornUuid,
        CreateNewbornPatientAction $action,
    ): RedirectResponse {
        $data = $request->validate([
            'last_name' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'in:M,F'],
        ]);

        $link = $action->execute($maternityRecord, $newbornUuid, $data, $request->user());

        return back()->with('status', "Dossier patient du nouveau-né {$link->patient->patient_number} ouvert.");
    }
}
