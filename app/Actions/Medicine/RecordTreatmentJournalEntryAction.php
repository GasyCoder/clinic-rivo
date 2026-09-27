<?php

namespace App\Actions\Medicine;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\TreatmentJournalEntry;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-116 — ajoute une ligne saisie à la main au journal de traitement.
 *
 * Append-only : il n'existe ni modification ni suppression. Une ligne
 * erronée se corrige par une nouvelle ligne qui le dit, comme un acte
 * réalisé (ADR-032). Le visa est l'utilisateur connecté, jamais un nom saisi.
 */
class RecordTreatmentJournalEntryAction
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @param array{occurred_at: string, description: string} $data */
    public function execute(Episode $episode, array $data, User $actor): TreatmentJournalEntry
    {
        if (! $actor->can('treatment_journal.record')) {
            throw new AuthorizationException('Vous n’avez pas le droit d’écrire dans le journal de traitement.');
        }

        return DB::transaction(function () use ($episode, $data, $actor): TreatmentJournalEntry {
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());

            // Un passage clos par la sortie administrative ne reçoit plus
            // rien (ADR-090) ; un passage annulé n'a jamais eu lieu.
            if ($locked->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'description' => 'Le passage est clos : le journal de traitement n’est plus modifiable.',
                ]);
            }

            $description = $this->richText->sanitize((string) $data['description']);

            if ($this->richText->isBlank($description)) {
                throw ValidationException::withMessages([
                    'description' => 'Décrivez le traitement administré.',
                ]);
            }

            return TreatmentJournalEntry::query()->create([
                'episode_id' => $locked->getKey(),
                'occurred_at' => Carbon::parse($data['occurred_at']),
                'description' => $description,
                'recorded_by' => $actor->getKey(),
            ]);
        });
    }
}
