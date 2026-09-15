<?php

namespace App\Support;

use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * One patient, one Soins handler at a time.
 *
 * Whoever took the patient in charge is the only person who fills the
 * worksheet, finishes the care or hands the patient over to Médecine. A
 * colleague who opens the same file — a stale tab, the queue on another
 * workstation — reads it but cannot act twice on the same patient: that is
 * how a patient ends up transferred to Médecine two times.
 *
 * Checked on the row the caller has already locked, so two simultaneous
 * clicks are serialised by the database and the second one lands here with
 * the first one's result, not with a stale page.
 */
class CareHandlerGuard
{
    /** A legacy row with no recorded handler stays workable by anyone authorised. */
    public static function isHandledBy(EpisodeOrientation $orientation, User $user): bool
    {
        return $orientation->status === EpisodeOrientationStatus::InProgress
            && ($orientation->accepted_by === null || $orientation->accepted_by === $user->getKey());
    }

    /** @throws ValidationException */
    public static function ensureWorkable(EpisodeOrientation $orientation, User $actor, string $key = 'orientation'): void
    {
        $orientation->loadMissing(['acceptedBy:id,name', 'completedBy:id,name']);

        $message = match ($orientation->status) {
            EpisodeOrientationStatus::Pending => 'Prenez d’abord ce patient en charge.',
            EpisodeOrientationStatus::Completed => sprintf(
                'Les soins de ce patient ont déjà été terminés%s%s. Aucune nouvelle action n’est possible sur cette prise en charge.',
                $orientation->completedBy ? ' par '.$orientation->completedBy->name : '',
                $orientation->completed_at ? ' le '.$orientation->completed_at->format('d/m/Y à H:i') : '',
            ),
            EpisodeOrientationStatus::Cancelled => 'Cette prise en charge Soins a été annulée.',
            default => self::isHandledBy($orientation, $actor)
                ? null
                : sprintf(
                    'Ce patient est pris en charge par %s. Seule la personne qui l’a pris en charge peut compléter la fiche ou le transférer.',
                    $orientation->acceptedBy?->name ?? 'un autre soignant',
                ),
        };

        if ($message !== null) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    /**
     * Corriger une fiche après le transfert vers Médecine.
     *
     * Décision explicite du propriétaire (2026-09-15) : une erreur de saisie
     * — 32 °C au lieu de 36,2 — doit pouvoir être rectifiée même une fois le
     * patient orienté, et par n'importe quel compte Soins autorisé, pas
     * seulement celui qui a pris le patient en charge : il peut avoir fini
     * son service.
     *
     * Cela amende l'ADR-085 sur deux points, volontairement :
     * l'interdiction d'agir sur des soins terminés, et l'exclusivité du
     * soignant. Ce qui reste intact : un passage n'est transféré vers
     * Médecine qu'une seule fois — `ensureWorkable()` continue de garder
     * cette transition-là.
     *
     * Chaque correction reste tracée : `CareRecord` est `Auditable` et
     * conserve l'ancienne comme la nouvelle valeur.
     *
     * @throws ValidationException
     */
    public static function ensureEditable(EpisodeOrientation $orientation, string $key = 'care_record'): void
    {
        if (in_array($orientation->status, [
            EpisodeOrientationStatus::InProgress,
            EpisodeOrientationStatus::Completed,
        ], true)) {
            return;
        }

        throw ValidationException::withMessages([
            $key => match ($orientation->status) {
                EpisodeOrientationStatus::Pending => 'Prenez d’abord ce patient en charge.',
                default => 'Cette prise en charge Soins a été annulée.',
            },
        ]);
    }

    /** Lecture seule côté écran : même règle que ensureEditable(). */
    public static function isEditable(EpisodeOrientation $orientation): bool
    {
        return in_array($orientation->status, [
            EpisodeOrientationStatus::InProgress,
            EpisodeOrientationStatus::Completed,
        ], true);
    }

    /** @throws ValidationException */
    public static function ensureAcceptable(EpisodeOrientation $orientation): void
    {
        if ($orientation->status === EpisodeOrientationStatus::Pending) {
            return;
        }

        $orientation->loadMissing(['acceptedBy:id,name', 'completedBy:id,name']);

        throw ValidationException::withMessages([
            'orientation' => match ($orientation->status) {
                EpisodeOrientationStatus::InProgress => sprintf(
                    'Ce patient a déjà été pris en charge par %s.',
                    $orientation->acceptedBy?->name ?? 'un autre soignant',
                ),
                EpisodeOrientationStatus::Completed => 'Les soins de ce patient sont déjà terminés.',
                default => 'Cette prise en charge Soins a été annulée.',
            },
        ]);
    }
}
