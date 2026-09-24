<?php

namespace App\Services\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\EpisodeOrientation;
use App\Models\SurgicalRequest;
use Illuminate\Support\Collection;

/**
 * Ce qui suit la Maternité, pour chaque passage d'une page (ADR-135).
 *
 * ADR-177 — la page Maternité ne liste plus des orientations mais les passages
 * ouverts (`ActiveEpisodeBoard`), comme les Soins et Médecine : ses vues
 * « à prendre / en cours / terminées » sont celles du tableau partagé. Reste
 * ici la seule lecture propre à la Maternité — où est allée la patiente
 * ensuite : le médecin, la césarienne.
 *
 * « Chez le médecin » ne se déduit jamais d'un libellé : c'est une vraie
 * orientation Médecine dont la source est la Maternité.
 */
class MaternityQueue
{
    /**
     * Ce qui suit la Maternité, par passage : l'orientation vers Médecine et
     * la césarienne demandée à Chirurgie. Deux requêtes pour toute la page,
     * jamais une par ligne.
     *
     * @param  Collection<int, int>  $episodeIds
     * @return array<int, array{medicine: ?array<string, mixed>, cesarean: ?array<string, mixed>}>
     */
    public function followUps(Collection $episodeIds): array
    {
        $ids = $episodeIds->unique()->values();

        $medicine = EpisodeOrientation::query()
            ->with('acceptedBy:id,name')
            ->whereIn('episode_id', $ids)
            ->where('source_module', CatalogModule::Maternity->value)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
            ->orderBy('id')
            ->get()
            ->groupBy('episode_id')
            ->map(fn (Collection $rows) => $rows->last());

        $cesarean = SurgicalRequest::query()
            ->whereIn('episode_id', $ids)
            ->where('procedure_name', 'like', 'Opération césarienne%')
            ->where('status', '!=', SurgicalRequestStatus::Cancelled->value)
            ->orderBy('id')
            ->get()
            ->groupBy('episode_id')
            ->map(fn (Collection $rows) => $rows->last());

        return $ids->mapWithKeys(fn (int $id) => [$id => [
            'medicine' => ($orientation = $medicine->get($id)) ? [
                'status' => $orientation->status->value,
                'label' => match ($orientation->status) {
                    EpisodeOrientationStatus::Pending => 'En attente du médecin',
                    EpisodeOrientationStatus::InProgress => 'Vue par le médecin',
                    default => 'Consultation terminée',
                },
                'doctor' => $orientation->acceptedBy?->name,
                'oriented_at' => $orientation->oriented_at,
            ] : null,
            'cesarean' => ($request = $cesarean->get($id)) ? [
                'status' => $request->status->value,
                'label' => $this->surgeryLabel($request->status),
                'scheduled_at' => $request->scheduled_at,
            ] : null,
        ]])->all();
    }

    private function surgeryLabel(SurgicalRequestStatus $status): string
    {
        return match ($status) {
            SurgicalRequestStatus::Pending => 'Césarienne demandée',
            SurgicalRequestStatus::Scheduled => 'Césarienne programmée',
            SurgicalRequestStatus::PreoperativeValidated => 'Césarienne validée avant bloc',
            SurgicalRequestStatus::InProgress => 'Césarienne en cours',
            SurgicalRequestStatus::Completed,
            SurgicalRequestStatus::Discharged => 'Césarienne réalisée',
            SurgicalRequestStatus::Cancelled => 'Césarienne annulée',
        };
    }
}
