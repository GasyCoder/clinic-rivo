<?php

namespace App\Actions\Episode;

use App\Enums\CareCompletionMode;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\CareOrder;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\CareWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-177, amendements du 2026-09-27 — le médecin envoie un patient aux Soins,
 * à tout moment du passage.
 *
 * Ce geste crée une **vraie orientation** Médecine → Soins, que les Soins
 * prennent comme toute demande. Il ne remplace pas l'ordre de soins de la
 * consultation (ADR-055), qui nomme des actes : il dit seulement « passez aux
 * Soins », avec une consigne facultative.
 *
 * ```text
 * avant la consultation    le patient garde sa place en Médecine ; à la fin des soins,
 *                          la suite prévue le ramène au médecin (ADR-166)
 * pendant la consultation  la consultation reste ouverte (ADR-088) : il revient chez vous
 * après la consultation    un soin oublié, demandé ensuite : le passage repasse « en soins »
 *                          s'il attendait son règlement (même règle que la réouverture, ADR-096)
 * ```
 *
 * Seule limite : les Soins ont déjà ce patient, en file ou en charge — rien à
 * envoyer. Les avoir déjà vus ne l'empêche pas : c'est une nouvelle demande. Un
 * passage clos, pas encore accueilli, ou dont le patient est décédé ou parti en
 * transfert ne s'envoie pas. `refusalFor()` porte la règle, lue par le tableau
 * et par l'action.
 *
 * ```text
 * annuler    tant que les Soins ne l'ont pas pris : l'orientation est annulée,
 *            jamais supprimée
 * ```
 *
 * Aucune permission nouvelle : c'est la même autorité que prendre le patient en
 * Médecine (`consultations.create`), vérifiée par la route.
 */
class SendEpisodeToCareAction
{
    public const REASON = 'Envoyé aux Soins par le médecin';

    /** Où en est la Médecine quand le patient part aux Soins. */
    public const BEFORE = 'BEFORE';

    public const DURING = 'DURING';

    public const AFTER = 'AFTER';

    /** Ce qui suit les soins : retour chez le médecin, fin aux Soins, ou choix de l'infirmier. */
    public const THEN_MEDICINE = 'MEDICINE';

    public const THEN_FINISH = 'FINISH';

    public const THEN_CHOICE = 'CHOICE';

    private const MOMENT_WORDS = [
        self::BEFORE => 'avant la consultation',
        self::DURING => 'pendant la consultation',
        self::AFTER => 'après la consultation',
    ];

    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly Auditor $auditor,
    ) {}

    public function execute(Episode $episode, User $actor, ?string $note = null): EpisodeOrientation
    {
        $note = trim((string) $note);

        return DB::transaction(function () use ($episode, $actor, $note): EpisodeOrientation {
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());
            $orientations = EpisodeOrientation::query()
                ->where('episode_id', $locked->getKey())
                ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
                ->lockForUpdate()
                ->get();

            $refusal = self::refusalFor($locked, $orientations);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['episode' => $refusal]);
            }

            $moment = self::moment($orientations);
            $reason = self::REASON.', '.self::MOMENT_WORDS[$moment].'.';

            $orientation = $this->createOrientation->execute(
                $locked,
                CatalogModule::Medicine,
                CatalogModule::Care,
                $actor,
                $note === '' ? $reason : $reason.' Consigne : '.$note,
            );

            // Le patient repart en soins : le laisser « en attente de règlement »
            // le montrerait à la Réception comme prêt à sortir pendant qu'on le
            // soigne. Un statut que la Réception a fait avancer plus loin n'est
            // jamais ramené en arrière (ADR-096).
            $settlement = $locked->administrative_status === EpisodeAdministrativeStatus::PendingSettlement;

            if ($settlement) {
                $locked->administrative_status = EpisodeAdministrativeStatus::InCare;
                $locked->saveQuietly();
            }

            $this->auditor->record(
                'episode.sent_to_care',
                entity: $locked,
                oldValues: $settlement ? ['administrative_status' => EpisodeAdministrativeStatus::PendingSettlement->value] : [],
                newValues: [
                    'orientation_uuid' => $orientation->uuid,
                    'moment' => $moment,
                    'note' => $note === '' ? null : $note,
                    ...($settlement ? ['administrative_status' => EpisodeAdministrativeStatus::InCare->value] : []),
                ],
                module: 'medicine',
                actor: $actor,
            );

            return $orientation;
        });
    }

    /** Annuler l'envoi tant que les Soins n'ont pas pris le patient. */
    public function withdraw(Episode $episode, User $actor): void
    {
        DB::transaction(function () use ($episode, $actor): void {
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());
            $sent = self::sentOrientation($locked, forUpdate: true);

            if ($sent === null) {
                throw ValidationException::withMessages([
                    'episode' => 'Ce patient n’a pas été envoyé aux Soins depuis la file Médecine, ou l’envoi a déjà été annulé.',
                ]);
            }

            if ($sent->status !== EpisodeOrientationStatus::Pending) {
                throw ValidationException::withMessages([
                    'episode' => 'Les Soins ont déjà pris ce patient en charge ('.($sent->acceptedBy?->name ?? 'un soignant').') : l’envoi ne s’annule plus.',
                ]);
            }

            $sent->cancel($actor);

            $this->auditor->record(
                'episode.sent_to_care.withdraw',
                entity: $locked,
                oldValues: ['orientation_uuid' => $sent->uuid],
                module: 'medicine',
                actor: $actor,
            );
        });
    }

    /**
     * L'orientation créée par ce geste : vers les Soins, venue de Médecine, en
     * attente ou prise, et jamais celle d'un ordre de soins (qui naît dans une
     * consultation, ADR-055).
     */
    public static function sentOrientation(Episode $episode, bool $forUpdate = false): ?EpisodeOrientation
    {
        $query = EpisodeOrientation::query()
            ->where('episode_id', $episode->getKey())
            ->where('destination_module', CatalogModule::Care->value)
            ->where('source_module', CatalogModule::Medicine->value)
            ->whereIn('status', [EpisodeOrientationStatus::Pending->value, EpisodeOrientationStatus::InProgress->value])
            ->whereNotIn('id', CareOrder::query()->whereNotNull('care_orientation_id')->select('care_orientation_id'))
            ->latest('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Pourquoi ce patient ne s'envoie pas aux Soins, ou `null` quand il le
     * peut. Une seule règle pour l'écran (proposer « Aux Soins ») et l'action.
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations  celles du passage, orientations annulées comprises ou non
     */
    public static function refusalFor(Episode $episode, Collection $orientations): ?string
    {
        $orientations = $orientations->filter(fn (EpisodeOrientation $orientation) => $orientation->status !== EpisodeOrientationStatus::Cancelled);

        if ($episode->status !== EpisodeStatus::Open) {
            return 'Ce passage n’est plus ouvert : il ne s’envoie plus aux Soins.';
        }

        if ($episode->service_plan_finalized_at === null && $episode->priority !== EpisodePriority::Emergency && $orientations->isEmpty()) {
            return 'L’accueil de ce passage n’est pas encore terminé à la Réception.';
        }

        if ($episode->medical_status === EpisodeMedicalStatus::Deceased) {
            return 'Le décès de ce patient a été prononcé : il ne s’envoie pas aux Soins.';
        }

        if ($episode->medical_status === EpisodeMedicalStatus::Transferred) {
            return 'Ce patient est parti en transfert : il ne s’envoie plus aux Soins.';
        }

        $care = $orientations->first(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Care
            && $orientation->status->isActive());

        if ($care !== null) {
            return $care->status === EpisodeOrientationStatus::Pending
                ? 'Ce patient est déjà en file aux Soins.'
                : 'Les Soins ont déjà ce patient en charge.';
        }

        return null;
    }

    /**
     * Ce qui suit les soins, dit avant d'envoyer le patient :
     *
     * ```text
     * la Médecine l'attend ou l'a en consultation   il revient chez le médecin
     * la Médecine l'a déjà vu (consultation close)   les soins terminent son parcours clinique
     * sinon                                          la suite prévue de son besoin (ADR-166)
     * ```
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations
     */
    public static function afterCare(Episode $episode, Collection $orientations): string
    {
        $medicine = $orientations->filter(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status !== EpisodeOrientationStatus::Cancelled);

        if ($medicine->contains(fn (EpisodeOrientation $orientation) => $orientation->status->isActive())) {
            return self::THEN_MEDICINE;
        }

        if ($medicine->isNotEmpty()) {
            return self::THEN_FINISH;
        }

        return match (app(CareWorkflow::class)->completionMode($episode)) {
            CareCompletionMode::Medicine => self::THEN_MEDICINE,
            CareCompletionMode::Finish => self::THEN_FINISH,
            CareCompletionMode::Choice => self::THEN_CHOICE,
        };
    }

    /**
     * Avant, pendant ou après la consultation — lu sur l'orientation Médecine
     * la plus avancée du passage.
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations
     */
    public static function moment(Collection $orientations): string
    {
        $medicine = $orientations->filter(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status !== EpisodeOrientationStatus::Cancelled);

        return match (true) {
            $medicine->contains(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::InProgress) => self::DURING,
            $medicine->contains(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::Completed)
                && ! $medicine->contains(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::Pending) => self::AFTER,
            default => self::BEFORE,
        };
    }
}
