<?php

namespace App\Services\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\EpisodeOrientation;
use App\Models\SurgicalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * La file Maternité et ses quatre vues (ADR-135).
 *
 * Les vues sont **exclusives** : une patiente est dans une seule, et la somme
 * des comptes est le nombre de dossiers de la file. Elles suivent le parcours
 * réel d'un passage — ce que les Soins font déjà avec leurs onglets
 * (ADR-124) :
 *
 * ```text
 * waiting    À prendre : orientée vers la Maternité, personne ne l'a encore prise
 * active     En cours : une sage-femme l'a prise en charge
 * doctor     Terminée ; la Maternité l'a orientée vers Médecine, qui n'a pas
 *            fini avec elle
 * completed  Terminée, sans suite en cours
 * ```
 *
 * « À prendre » et « En cours » sont deux états, pas un : dans une seule vue,
 * le libellé « En cours » couvrait aussi des patientes que personne n'avait
 * prises (constaté à l'usage).
 *
 * « Chez le médecin » ne se déduit jamais d'un libellé : c'est une vraie
 * orientation Médecine dont la source est la Maternité, encore en attente ou
 * en cours. Dès que le médecin a terminé, la patiente retombe en « Terminées »
 * — le dossier reste consultable, mais plus personne ne l'attend.
 */
class MaternityQueue
{
    public const FILTERS = ['waiting', 'active', 'doctor', 'completed'];

    /** Les statuts d'une orientation Médecine qui « a encore » la patiente. */
    private const DOCTOR_HAS_PATIENT = [
        EpisodeOrientationStatus::Pending,
        EpisodeOrientationStatus::InProgress,
    ];

    /** Une valeur inconnue retombe sur le travail à faire, jamais sur l'historique. */
    public function normalize(mixed $filter): string
    {
        return in_array($filter, self::FILTERS, true) ? $filter : 'waiting';
    }

    /** Toutes les orientations Maternité visibles : passage ouvert, dossier patient présent. */
    public function base(): Builder
    {
        return EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Maternity->value)
            ->whereHas('episode', fn ($episode) => $episode->where('status', 'OPEN'))
            // Un patient n'est jamais réellement supprimé (ADR-010) : cette
            // garde ne protège que d'une corruption qui contournerait
            // Eloquent, pour qu'une ligne orpheline quitte la file au lieu de
            // faire tomber toute la page.
            ->whereHas('episode.patient');
    }

    public function constrain(Builder $query, string $filter): Builder
    {
        return match ($this->normalize($filter)) {
            'doctor' => $query
                ->where('status', EpisodeOrientationStatus::Completed->value)
                ->whereHas('episode.orientations', fn ($orientation) => $this->handedToDoctor($orientation)),
            'completed' => $query
                ->where('status', EpisodeOrientationStatus::Completed->value)
                ->whereDoesntHave('episode.orientations', fn ($orientation) => $this->handedToDoctor($orientation)),
            'active' => $query->where('status', EpisodeOrientationStatus::InProgress->value),
            default => $query->where('status', EpisodeOrientationStatus::Pending->value),
        };
    }

    /**
     * Les comptes de la file, un par vue.
     *
     * @return array{waiting: int, active: int, doctor: int, completed: int}
     */
    public function counts(): array
    {
        return [
            'waiting' => $this->constrain($this->base(), 'waiting')->count(),
            'active' => $this->constrain($this->base(), 'active')->count(),
            'doctor' => $this->constrain($this->base(), 'doctor')->count(),
            'completed' => $this->constrain($this->base(), 'completed')->count(),
        ];
    }

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

    private function handedToDoctor($orientation): void
    {
        $orientation
            ->where('source_module', CatalogModule::Maternity->value)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->whereIn('status', array_map(fn ($status) => $status->value, self::DOCTOR_HAS_PATIENT));
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
