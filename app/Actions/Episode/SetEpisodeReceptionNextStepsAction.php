<?php

namespace App\Actions\Episode;

use App\Enums\EpisodeStatus;
use App\Enums\ReceptionNextStep;
use App\Models\Episode;
use App\Models\EpisodeReceptionNextStep;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-177 — enregistre la prochaine étape **suggérée** par la Réception.
 *
 * L'ensemble transmis remplace le précédent : aucune case cochée est une
 * réponse valide, qui efface la suggestion. Rien d'autre ne change — ni
 * orientation, ni consultation, ni fiche de soins, ni visibilité : un service
 * autorisé voit le passage avec ou sans suggestion.
 *
 * Le changement est tracé une seule fois, avec l'ancien et le nouvel ensemble,
 * et seulement s'il y a un changement : réenvoyer la même sélection n'écrit
 * rien.
 */
class SetEpisodeReceptionNextStepsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  iterable<ReceptionNextStep|string>  $steps
     * @return list<ReceptionNextStep> la suggestion enregistrée, dans l'ordre canonique
     */
    public function execute(Episode $episode, iterable $steps, User $actor): array
    {
        $wanted = $this->normalize($steps);

        return DB::transaction(function () use ($episode, $wanted, $actor): array {
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());

            if ($locked->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'next_steps' => 'La prochaine étape suggérée ne se modifie que sur un passage ouvert.',
                ]);
            }

            $current = $this->currentOf($locked);

            if ($current === $wanted) {
                return $this->asEnums($current);
            }

            EpisodeReceptionNextStep::query()
                ->where('episode_id', $locked->getKey())
                ->when($wanted !== [], fn ($query) => $query->whereNotIn('module', $wanted))
                ->delete();

            foreach (array_diff($wanted, $current) as $module) {
                EpisodeReceptionNextStep::query()->create([
                    'episode_id' => $locked->getKey(),
                    'module' => $module,
                    'created_by' => $actor->getKey(),
                ]);
            }

            $this->auditor->record(
                'episode.next_steps.update',
                entity: $locked,
                newValues: ['next_steps' => $wanted],
                oldValues: ['next_steps' => $current],
                module: 'reception',
                actor: $actor,
            );

            return $this->asEnums($wanted);
        });
    }

    /**
     * @param  iterable<ReceptionNextStep|string>  $steps
     * @return list<string>
     */
    private function normalize(iterable $steps): array
    {
        $values = [];

        foreach ($steps as $step) {
            $value = $step instanceof ReceptionNextStep ? $step->value : strtoupper(trim((string) $step));

            if (ReceptionNextStep::tryFrom($value) === null) {
                throw ValidationException::withMessages([
                    'next_steps' => 'Une prochaine étape suggérée est inconnue.',
                ]);
            }

            $values[] = $value;
        }

        return array_values(array_intersect(ReceptionNextStep::values(), array_unique($values)));
    }

    /** @return list<string> */
    private function currentOf(Episode $episode): array
    {
        $stored = EpisodeReceptionNextStep::query()
            ->where('episode_id', $episode->getKey())
            ->get()
            ->map(fn (EpisodeReceptionNextStep $step): string => $step->module->value)
            ->all();

        return array_values(array_intersect(ReceptionNextStep::values(), $stored));
    }

    /**
     * @param  list<string>  $values
     * @return list<ReceptionNextStep>
     */
    private function asEnums(array $values): array
    {
        return array_map(fn (string $value): ReceptionNextStep => ReceptionNextStep::from($value), $values);
    }
}
