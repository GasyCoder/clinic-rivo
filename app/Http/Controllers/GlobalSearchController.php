<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La recherche de l'en-tête : un patient, ou le passage qui le désigne.
 *
 * Elle ne cherche que ce que le compte a le droit de voir. `patients.view`
 * gouverne l'ensemble : un numéro de passage ramène son patient, donc
 * l'exposer sans ce droit reviendrait à contourner la même protection.
 *
 * Aucun index plein texte n'est inventé : ce sont les mêmes colonnes et le
 * même `LIKE` que le répertoire `/patients`, pour que les deux écrans ne
 * puissent pas répondre différemment à la même saisie.
 */
class GlobalSearchController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        // Deux caractères ne discriminent rien : la requête ramènerait la
        // moitié du répertoire pour un coût inutile.
        if (mb_strlen($term) < 2) {
            return response()->json(['patients' => [], 'episodes' => [], 'term' => $term]);
        }

        $like = '%'.$term.'%';

        $patients = Patient::query()
            ->where(fn ($query) => $query
                ->where('patient_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->orderBy('last_name')
            ->limit(self::LIMIT)
            ->get(['uuid', 'patient_number', 'first_name', 'last_name', 'phone'])
            ->map(fn (Patient $patient): array => [
                'uuid' => $patient->uuid,
                'label' => trim($patient->first_name.' '.$patient->last_name),
                'meta' => trim($patient->patient_number.($patient->phone ? ' · '.$patient->phone : '')),
                'url' => "/patients/{$patient->uuid}",
            ])
            ->values();

        $episodes = Episode::query()
            ->with('patient:id,uuid,first_name,last_name')
            ->where('episode_number', 'like', $like)
            ->latest('started_at')
            ->limit(self::LIMIT)
            ->get(['id', 'uuid', 'patient_id', 'episode_number', 'status', 'started_at'])
            // Un passage orphelin de son patient ne désigne plus personne.
            ->filter(fn (Episode $episode): bool => $episode->patient !== null)
            ->map(fn (Episode $episode): array => [
                'uuid' => $episode->uuid,
                'label' => $episode->episode_number,
                // Le nom et la date, pas un libellé de statut : `EpisodeStatus`
                // n'en porte aucun, et en composer un ici en inventerait un
                // second vocabulaire à côté des écrans qui en ont déjà un.
                'meta' => trim(
                    $episode->patient->first_name.' '.$episode->patient->last_name
                    .($episode->started_at ? ' · '.$episode->started_at->format('d/m/Y') : ''),
                ),
                'url' => "/passages/{$episode->uuid}",
            ])
            ->values();

        return response()->json([
            'term' => $term,
            'patients' => $patients,
            'episodes' => $episodes,
        ]);
    }
}
