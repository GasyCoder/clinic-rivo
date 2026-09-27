<?php

namespace App\Support\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use Illuminate\Validation\ValidationException;

/**
 * ADR-204 — d'où part une demande d'examen écrite depuis la Maternité ;
 * ADR-205 — et une ordonnance de la sage-femme.
 *
 * Même principe que `StayOrderContext` (ADR-162) : la seule différence avec une
 * demande de consultation est ce qu'on vérifie avant d'écrire — ici, une prise
 * en charge Maternité en cours, un passage ouvert et un dossier pas encore
 * terminé. Tout ce qui suit (catalogue, doublons, facturation, orientation vers
 * le Laboratoire) reste celui des actions existantes.
 */
final class MaternityOrderContext
{
    private function __construct(
        public readonly MaternityRecord $record,
        public readonly Episode $episode,
        public readonly EpisodeOrientation $orientation,
    ) {}

    /** À appeler dans la transaction de l'action : verrouille la prise en charge et son dossier. */
    public static function lock(EpisodeOrientation $orientation, string $errorKey): self
    {
        $locked = EpisodeOrientation::query()->lockForUpdate()->findOrFail($orientation->getKey());

        if ($locked->destination_module !== CatalogModule::Maternity) {
            abort(404);
        }

        if ($locked->status !== EpisodeOrientationStatus::InProgress) {
            throw ValidationException::withMessages([
                $errorKey => 'La prise en charge Maternité n’est plus en cours : aucune demande ni ordonnance ne peut en partir.',
            ]);
        }

        $episode = Episode::query()->lockForUpdate()->findOrFail($locked->episode_id);

        if ($episode->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages([$errorKey => 'Ce passage est clos.']);
        }

        $record = MaternityRecord::query()
            ->where('episode_id', $episode->getKey())
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Commencez d’abord la consultation ou l’accouchement : la demande se rattache à ce dossier.',
            ]);
        }

        if ($record->isFinalized()) {
            throw ValidationException::withMessages([
                $errorKey => 'Ce dossier Maternité est terminé : il ne reçoit plus de demande ni d’ordonnance.',
            ]);
        }

        return new self($record, $episode, $locked);
    }
}
