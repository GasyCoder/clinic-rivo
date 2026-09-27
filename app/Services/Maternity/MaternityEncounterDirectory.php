<?php

namespace App\Services\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeStatus;
use App\Enums\MaternityEncounterType;
use App\Models\Episode;
use App\Models\MaternityRecord;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ADR-204 — ce que la file Maternité sait du parcours de chaque passage.
 *
 * Trois sources, dans cet ordre :
 *
 * ```text
 * CHOISI      le parcours du dossier Maternité, choisi par la sage-femme
 * DÉDUIT      un dossier d'avant ce choix se lit sur son contenu
 *             (`effectiveEncounterType()`, comme sur la page du dossier)
 * SUGGÉRÉ     sans dossier, ce que les actes demandés à la Réception laissent
 *             attendre — une présélection, jamais un choix fait à sa place
 * ```
 *
 * Un passage sans l'un ni l'autre reste « à préciser » : il ne se range dans
 * aucun onglet, et paraît dans « Tous ». L'onglet et la ligne lisent la même
 * chose : un dossier affiché « Consultation prénatale » est compté dans
 * « Consultations ».
 */
final class MaternityEncounterDirectory
{
    public const FILTERS = ['all', 'PRENATAL', 'DELIVERY'];

    /** @var array<string, list<int>> les passages d'avant le choix, par parcours déduit — lus une fois */
    private array $legacy = [];

    public function normalize(mixed $type): string
    {
        return in_array($type, self::FILTERS, true) ? $type : 'all';
    }

    /** Le filtre du tableau pour un onglet ; `null` pour « Tous ». */
    public function refine(string $filter): ?Closure
    {
        $type = MaternityEncounterType::tryFrom($filter);

        if ($type === null) {
            return null;
        }

        $delivery = MaternityEncounterType::Delivery->suggestingCodes();
        $legacy = $this->legacyEpisodeIds($type);

        return fn (Builder $query) => $query->where(fn (Builder $where) => $where
            ->whereHas('maternityRecord', fn (Builder $record) => $record->where('encounter_type', $type->value))
            ->when($legacy !== [], fn (Builder $inferred) => $inferred->orWhereIn('id', $legacy))
            ->orWhere(fn (Builder $suggested) => $suggested
                // Dès qu'un dossier existe, c'est lui qui dit le parcours, plus la suggestion.
                ->whereDoesntHave('maternityRecord')
                ->whereHas('serviceRequests', fn (Builder $request) => $request
                    ->where('module', CatalogModule::Maternity->value)
                    ->whereIn('catalog_code', $type->suggestingCodes()))
                // Un accouchement l'emporte : un passage qui porte les deux n'est pas une consultation.
                ->when($type === MaternityEncounterType::Prenatal, fn (Builder $prenatal) => $prenatal
                    ->whereDoesntHave('serviceRequests', fn (Builder $request) => $request
                        ->where('module', CatalogModule::Maternity->value)
                        ->whereIn('catalog_code', $delivery)))));
    }

    /**
     * Par identifiant local de passage : le parcours du dossier (choisi, ou
     * déduit pour un dossier d'avant ce choix), sinon celui que la Réception
     * suggère, et quand le dossier a été enregistré pour la dernière fois.
     *
     * @param  Collection<int, int>  $episodeIds
     * @return array<int, array{type: ?string, type_label: ?string, inferred: bool, suggested: ?string, suggested_label: ?string, record_updated_at: mixed, finalized: bool}>
     */
    public function forEpisodes(Collection $episodeIds): array
    {
        return Episode::query()
            ->whereIn('id', $episodeIds)
            ->with([
                'maternityRecord:id,episode_id,encounter_type,labor_data,delivery_data,updated_at,completed_at',
                'serviceRequests:id,episode_id,module,catalog_code',
            ])
            ->get()
            ->mapWithKeys(function (Episode $episode): array {
                $record = $episode->maternityRecord;
                $type = $record?->effectiveEncounterType();
                $suggested = $record === null
                    ? MaternityEncounterType::suggestedFor($episode->serviceRequests
                        ->filter(fn ($request) => $request->module === CatalogModule::Maternity)
                        ->pluck('catalog_code'))
                    : null;

                return [$episode->getKey() => [
                    'type' => $type?->value,
                    'type_label' => $type?->label(),
                    'inferred' => $record !== null && $record->encounter_type === null,
                    'suggested' => $suggested?->value,
                    'suggested_label' => $suggested?->label(),
                    'record_updated_at' => $record?->updated_at,
                    'finalized' => (bool) $record?->isFinalized(),
                ]];
            })
            ->all();
    }

    /**
     * Les passages ouverts dont le dossier date d'avant le choix du parcours,
     * rangés par parcours déduit. Un ensemble petit, qui ne grandit plus : tout
     * nouveau dossier naît avec son parcours.
     *
     * @return list<int>
     */
    private function legacyEpisodeIds(MaternityEncounterType $type): array
    {
        if ($this->legacy === []) {
            $this->legacy = ['PRENATAL' => [], 'DELIVERY' => []];

            MaternityRecord::query()
                ->whereNull('encounter_type')
                ->whereHas('episode', fn (Builder $episode) => $episode->where('status', EpisodeStatus::Open->value))
                ->get(['id', 'episode_id', 'labor_data', 'delivery_data'])
                ->each(function (MaternityRecord $record): void {
                    $this->legacy[$record->effectiveEncounterType()->value][] = (int) $record->episode_id;
                });
        }

        return $this->legacy[$type->value] ?? [];
    }
}
