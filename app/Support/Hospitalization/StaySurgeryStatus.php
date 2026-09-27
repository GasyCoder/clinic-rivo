<?php

namespace App\Support\Hospitalization;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-160 — où en est le passage d'un patient hospitalisé au bloc, lu sur la
 * liste des hospitalisés.
 *
 * Le patient garde son lit pendant le bloc : sans repère, la liste le montrait
 * « au lit » alors qu'il attend l'intervention ou qu'il est déjà en salle. Rien
 * n'est recopié : l'état se lit sur la demande chirurgicale du passage, la même
 * que suivent la Chirurgie et la page du séjour. Une demande annulée n'est plus
 * un passage au bloc et n'est jamais lue ici.
 */
final class StaySurgeryStatus
{
    /** Du plus avancé au moins avancé : le patient en salle l'emporte sur une demande à programmer. */
    private const RANK = [
        SurgicalRequestStatus::InProgress->value => 4,
        SurgicalRequestStatus::PreoperativeValidated->value => 3,
        SurgicalRequestStatus::Scheduled->value => 2,
        SurgicalRequestStatus::Pending->value => 1,
    ];

    /** Une demande que le bloc n'a pas encore terminée : le patient va au bloc, ou y est. */
    public static function activeStatuses(): array
    {
        return array_keys(self::RANK);
    }

    /** Limite une requête de séjours à ceux dont le passage va au bloc, ou y est. */
    public static function constrainToActive(Builder $stays): Builder
    {
        return $stays->whereIn(
            'episode_id',
            SurgicalRequest::query()->whereIn('status', self::activeStatuses())->select('episode_id'),
        );
    }

    /**
     * Le passage au bloc de chaque passage, en une requête pour toute la page.
     *
     * Une demande en cours l'emporte (la plus avancée) ; à défaut, la dernière
     * intervention terminée, pour que le post-opératoire se lise aussi. Un
     * passage sans demande n'a pas de clé : aucun repère, jamais un repère vide.
     *
     * @param  list<int>  $episodeIds
     * @return array<int, array<string, mixed>>
     */
    public static function forEpisodes(array $episodeIds, bool $canOpen): array
    {
        if ($episodeIds === []) {
            return [];
        }

        return SurgicalRequest::query()
            ->whereIn('episode_id', $episodeIds)
            ->where('status', '!=', SurgicalRequestStatus::Cancelled->value)
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'episode_id', 'status', 'procedure_name', 'scheduled_at', 'completed_at', 'discharged_at'])
            ->groupBy('episode_id')
            ->map(function ($requests) use ($canOpen): array {
                $active = $requests
                    ->filter(fn (SurgicalRequest $request): bool => isset(self::RANK[$request->status->value]))
                    ->sortByDesc(fn (SurgicalRequest $request): int => self::RANK[$request->status->value])
                    ->values();
                $current = $active->first() ?? $requests->first();

                return [
                    'uuid' => $current->uuid,
                    'status' => $current->status->value,
                    'active' => $active->isNotEmpty(),
                    'procedure_name' => $current->procedure_name,
                    'scheduled_at' => $current->scheduled_at,
                    'done_at' => $current->discharged_at ?? $current->completed_at,
                    // Une seconde intervention en attente ne disparaît pas derrière la première.
                    'others' => max(0, $active->count() - 1),
                    // Le dossier du bloc ne s'ouvre qu'avec son droit (ADR-146 : pas de lien vers un refus).
                    'url' => $canOpen ? "/surgery/{$current->uuid}" : null,
                ];
            })
            ->all();
    }
}
