<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\RecordTreatmentJournalEntryAction;
use App\Http\Requests\StoreTreatmentJournalEntryRequest;
use App\Models\Episode;
use App\Services\Medicine\TreatmentJournal;
use App\Support\Documents\PaperPatient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-116 — le « DOSSIER MÉDICAL – TRAITEMENT » de la clinique.
 *
 * La page est à la fois l'écran de saisie (pour Médecine et Soins) et la
 * feuille imprimable : les deux usages lisent la même chronologie, et rien
 * n'est jamais recopié pour l'impression.
 */
class TreatmentJournalController extends Controller
{
    public function show(Request $request, Episode $episode, TreatmentJournal $journal): Response
    {
        $user = $request->user();

        $episode->load('patient:id,uuid,patient_number,first_name,last_name');

        return Inertia::render('Medicine/TreatmentJournalSheet', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'status' => $episode->status->value,
            ],
            'patient' => PaperPatient::present($episode->patient),
            'rows' => $journal->rows($episode, $user),
            'can_record' => $user->can('treatment_journal.record') && $episode->status->value === 'OPEN',
        ]);
    }

    public function store(
        StoreTreatmentJournalEntryRequest $request,
        Episode $episode,
        RecordTreatmentJournalEntryAction $action,
    ): RedirectResponse {
        $action->execute($episode, $request->validated(), $request->user());

        return back()->with('status', 'Ligne ajoutée au journal de traitement.');
    }
}
