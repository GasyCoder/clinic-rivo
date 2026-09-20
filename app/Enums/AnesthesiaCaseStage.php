<?php

namespace App\Enums;

use App\Models\SurgicalRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Où en est un dossier du point de vue de l'anesthésiste (ADR-135).
 *
 * Le dossier d'anesthésie n'existe pas seul : il est porté par la demande
 * chirurgicale (ADR-048). Ses étapes sont donc lues sur des faits qui existent
 * déjà — l'évaluation pré-anesthésique validée, l'état de la demande, la
 * validation du dossier — jamais sur un drapeau de plus.
 *
 * ```text
 * ASSESS   à évaluer ou évaluation en cours (pas encore validée)
 * CLEARED  évaluation validée : le dossier est prêt pour Chirurgie, qui n'est
 *          pas encore au bloc
 * INTRA    la Chirurgie est au bloc : conduite anesthésique en cours
 * DONE     l'intervention est terminée, ou le dossier anesthésique validé
 * ```
 *
 * Les quatre étapes sont **exclusives et complètes** sur les demandes non
 * annulées : la somme des comptes est le nombre de dossiers. Une demande
 * annulée (retirée par Médecine avant que Chirurgie la prenne, ADR-084) n'a
 * jamais eu de travail d'anesthésie et n'apparaît nulle part.
 */
enum AnesthesiaCaseStage: string
{
    case Assess = 'assess';
    case Cleared = 'cleared';
    case Intra = 'intra';
    case Done = 'done';

    /** Une valeur inconnue retombe sur le travail à faire, jamais sur l'historique. */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Assess) : self::Assess;
    }

    public function label(): string
    {
        return match ($this) {
            self::Assess => 'À évaluer',
            self::Cleared => 'Transmis à Chirurgie',
            self::Intra => 'Au bloc',
            self::Done => 'Terminés',
        };
    }

    /** Ce que la ligne dit du dossier, à côté de la pastille. */
    public function rowLabel(): string
    {
        return match ($this) {
            self::Assess => 'Évaluation à faire',
            self::Cleared => 'Prêt pour Chirurgie',
            self::Intra => 'Conduite au bloc',
            self::Done => 'Dossier terminé',
        };
    }

    /** Les demandes que le bloc n'a pas encore prises : la seule fenêtre où l'évaluation se joue. */
    private const BEFORE_BLOCK = [
        SurgicalRequestStatus::Pending,
        SurgicalRequestStatus::Scheduled,
        SurgicalRequestStatus::PreoperativeValidated,
    ];

    /** @param  Builder<SurgicalRequest>  $query */
    public function constrain(Builder $query): Builder
    {
        $beforeBlock = array_map(fn ($status) => $status->value, self::BEFORE_BLOCK);
        $finished = [SurgicalRequestStatus::Completed->value, SurgicalRequestStatus::Discharged->value];
        $recordValidated = fn ($record) => $record->whereNotNull('validated_at');

        $query->where('status', '!=', SurgicalRequestStatus::Cancelled->value);

        return match ($this) {
            self::Done => $query->where(fn ($q) => $q
                ->whereIn('status', $finished)
                ->orWhereHas('anesthesiaRecord', $recordValidated)),
            self::Intra => $query
                ->where('status', SurgicalRequestStatus::InProgress->value)
                ->whereDoesntHave('anesthesiaRecord', $recordValidated),
            self::Cleared => $query
                ->whereIn('status', $beforeBlock)
                ->whereHas('anesthesiaRecord', fn ($record) => $record
                    ->whereNotNull('assessment_validated_at')
                    ->whereNull('validated_at')),
            self::Assess => $query
                ->whereIn('status', $beforeBlock)
                ->where(fn ($q) => $q
                    ->whereDoesntHave('anesthesiaRecord')
                    ->orWhereHas('anesthesiaRecord', fn ($record) => $record
                        ->whereNull('assessment_validated_at')
                        ->whereNull('validated_at'))),
        };
    }

    /** La même règle que `constrain()`, lue sur un dossier chargé. */
    public static function of(SurgicalRequest $request): self
    {
        $record = $request->anesthesiaRecord;

        if (in_array($request->status, [SurgicalRequestStatus::Completed, SurgicalRequestStatus::Discharged], true)
            || $record?->validated_at !== null) {
            return self::Done;
        }

        if ($request->status === SurgicalRequestStatus::InProgress) {
            return self::Intra;
        }

        return $record?->assessment_validated_at !== null ? self::Cleared : self::Assess;
    }
}
