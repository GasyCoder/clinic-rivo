<?php

namespace App\Actions\Guarding;

use App\Enums\EpisodeAdministrativeStatus;
use App\Models\Episode;
use App\Models\EpisodeExitControl;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-116 — le gardien constate la sortie d'un patient à la porte.
 *
 * C'est la « Signature Service Sécurité » du ticket de sortie. Elle n'est
 * possible qu'après la sortie administrative prononcée par la Caisse :
 * payée comptant, ou dette validée par une personne habilitée (ADR-090).
 * Un évadé est déjà parti sans passer la porte, et un passage encore à
 * régler doit retourner à la Réception — le gardien ne décide jamais d'une
 * sortie, il la constate.
 */
class RecordExitControlAction
{
    /** Les sorties administratives qui autorisent à franchir la porte. */
    public const ALLOWED_STATUSES = [
        EpisodeAdministrativeStatus::DischargedPaid,
        EpisodeAdministrativeStatus::DischargedDebt,
    ];

    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Episode $episode, User $actor, ?string $notes = null): EpisodeExitControl
    {
        if (! $actor->can('guarding.entries.close')) {
            throw new AuthorizationException('Vous n’avez pas le droit d’enregistrer une sortie.');
        }

        return DB::transaction(function () use ($episode, $actor, $notes): EpisodeExitControl {
            $locked = Episode::query()->with('exitControl.controlledBy:id,name')->lockForUpdate()->findOrFail($episode->getKey());

            if ($locked->exitControl !== null) {
                throw ValidationException::withMessages([
                    'episode' => sprintf(
                        'Sortie déjà constatée le %s par %s.',
                        $locked->exitControl->controlled_at->format('d/m/Y à H:i'),
                        $locked->exitControl->controlledBy?->name ?? 'un autre compte',
                    ),
                ]);
            }

            if (! in_array($locked->administrative_status, self::ALLOWED_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'episode' => 'La sortie n’a pas été enregistrée par la Caisse : orientez le patient vers la Réception.',
                ]);
            }

            $notes = trim((string) $notes);

            $control = EpisodeExitControl::query()->create([
                'episode_id' => $locked->getKey(),
                'controlled_at' => now(),
                'controlled_by' => $actor->getKey(),
                'notes' => $notes === '' ? null : $notes,
            ]);

            $this->auditor->record(
                action: 'episode.exit_control',
                entity: $locked,
                newValues: [
                    'controlled_at' => $control->controlled_at->toIso8601String(),
                    'administrative_status' => $locked->administrative_status?->value,
                ],
                module: 'guarding',
            );

            return $control;
        });
    }
}
