<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\Medicine\TreatmentJournal;
use App\Support\Documents\PaperPatient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-118 — tous les « Dossier médical – Traitement » d'un patient, en un
 * seul document.
 *
 * Le journal de traitement est tenu par passage (ADR-116) : pour remettre ou
 * archiver le dossier d'un patient, il fallait ouvrir chaque passage, imprimer
 * chaque feuille, et les réunir à la main. Cette page les rassemble, du plus
 * ancien au plus récent — l'ordre dans lequel un dossier se lit —, et l'unique
 * impression produit un seul PDF.
 *
 * Rien n'est recomposé ici : chaque feuille est celle que `TreatmentJournal`
 * sert déjà pour son passage, avec les mêmes permissions par source. La page
 * ne fait que réunir ; elle ne permet aucune saisie (une ligne s'ajoute
 * toujours depuis le journal du passage concerné).
 */
class PatientTreatmentJournalController extends Controller
{
    public function show(Request $request, Patient $patient, TreatmentJournal $journal): Response
    {
        $user = $request->user();

        $patient->loadMissing('addressEntry:id,uuid,label');

        $passages = $patient->episodes()
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(function ($episode) use ($journal, $user) {
                $rows = $journal->rows($episode, $user);

                return [
                    'uuid' => $episode->uuid,
                    'episode_number' => $episode->episode_number,
                    'status' => $episode->status->value,
                    'priority' => $episode->priority->value,
                    'started_at' => $episode->started_at,
                    'ended_at' => $episode->ended_at,
                    'rows_count' => count($rows),
                    'rows' => $rows,
                ];
            })
            ->values();

        return Inertia::render('Medicine/PatientTreatmentJournals', [
            'patient' => [...PaperPatient::present($patient), 'uuid' => $patient->uuid],
            'passages' => $passages,
            'totals' => [
                'passages' => $passages->count(),
                'with_rows' => $passages->where('rows_count', '>', 0)->count(),
                'rows' => $passages->sum('rows_count'),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
