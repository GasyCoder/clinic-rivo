<?php

namespace App\Support;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionNextStep;
use App\Enums\ReceptionRoutingMode;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeReceptionNextStep;
use App\Models\EpisodeServiceRequest;
use Illuminate\Support\Collection;

/**
 * ADR-177, amendement — par où un passage devrait entrer : aux Soins d'abord,
 * ou directement chez le médecin.
 *
 * Deux signaux, déjà consignés à l'accueil, le disent :
 *
 * ```text
 * suggestion   la prochaine étape cochée par la Réception (Soins, Médecine)
 * besoin       le parcours de la désignation : CARE_ONLY et CARE_THEN_MEDICINE
 *              passent par les Soins, MEDICINE_DIRECT va au médecin (ADR-030)
 * ```
 *
 * La suggestion, choisie pour ce passage précis, l'emporte sur le parcours du
 * catalogue : un passage suggéré « Médecine » n'est pas renvoyé aux Soins
 * parce que sa désignation y passe d'ordinaire.
 *
 * ```text
 * Médecine prend un patient attendu aux Soins   CARE_FIRST    on le dit, le médecin décide
 * Soins prend un patient attendu en Médecine    MEDICINE_ONLY on le dit, les Soins décident
 * ```
 *
 * **Rien n'est refusé** (amendement du 2026-09-27 bis, qui renverse le refus
 * des Soins posé le 2026-09-23) : tout le personnel clinique peut prendre un
 * patient, et le système dit seulement ce qui était prévu. Un patient attendu
 * en Médecine et pris aux Soins garde sa place chez le médecin ; à la fin des
 * soins, la suite prévue l'y conduit (ADR-166).
 *
 * Il reste hors de la **file numérotée** des Soins : le n° 1 y est le prochain
 * patient qui vient pour les Soins, pas celui qu'on peut y prendre en passant.
 *
 * Le rappel se tait dès que la situation l'a dépassé : un soin demandé par le
 * médecin (une vraie orientation vers les Soins, en attente), l'urgence
 * (ADR-021), un médecin qui a déjà le patient ou l'a déjà vu.
 *
 * Aucune donnée clinique n'est lue : seulement la suggestion, le parcours des
 * désignations et les orientations — de l'information de routage (ADR-117).
 */
final class EpisodeEntryPath
{
    public const CARE_FIRST = 'CARE_FIRST';

    public const MEDICINE_ONLY = 'MEDICINE_ONLY';

    /**
     * Ce que le service qui prend le patient doit savoir, ou `null` quand rien
     * n'est à rappeler. Ce n'est jamais un refus. Les relations
     * `serviceRequests`, `receptionNextSteps` et `orientations` sont lues
     * chargées si elles le sont, interrogées sinon.
     *
     * @return array{code: string, title: string, message: string, reasons: list<string>}|null
     */
    public static function guard(Episode $episode, CatalogModule $module): ?array
    {
        if (! in_array($module, [CatalogModule::Care, CatalogModule::Medicine], true)
            || $episode->priority === EpisodePriority::Emergency) {
            return null;
        }

        $orientations = self::relation($episode, 'orientations')
            ->filter(fn (EpisodeOrientation $orientation) => $orientation->status !== EpisodeOrientationStatus::Cancelled);

        // Une vraie orientation vers ce service l'attend : c'est une demande qui
        // lui est adressée, et elle se prend toujours.
        if ($orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->destination_module === $module
            && $orientation->status === EpisodeOrientationStatus::Pending)) {
            return null;
        }

        $suggested = self::relation($episode, 'receptionNextSteps')
            ->map(fn (EpisodeReceptionNextStep $step) => $step->module)
            ->all();
        $careSuggested = in_array(ReceptionNextStep::Care, $suggested, true);
        $medicineSuggested = in_array(ReceptionNextStep::Medicine, $suggested, true);

        $requests = self::relation($episode, 'serviceRequests');
        $careNeeds = $requests->filter(fn (EpisodeServiceRequest $request) => in_array($request->routing_mode, [
            ReceptionRoutingMode::CareOnly,
            ReceptionRoutingMode::CareThenMedicine,
        ], true))->values();
        $medicineNeeds = $requests
            ->filter(fn (EpisodeServiceRequest $request) => $request->routing_mode === ReceptionRoutingMode::MedicineDirect)
            ->values();

        if ($module === CatalogModule::Medicine) {
            return self::careFirst($orientations, $careSuggested, $medicineSuggested, $careNeeds);
        }

        return self::medicineOnly($orientations, $careSuggested, $medicineSuggested, $careNeeds, $medicineNeeds);
    }

    /**
     * Attendu ailleurs que dans ce service : visible et possible à prendre,
     * mais hors de sa file numérotée.
     *
     * @param  array{code: string}|null  $guard
     */
    public static function expectedElsewhere(?array $guard): bool
    {
        return ($guard['code'] ?? null) === self::MEDICINE_ONLY;
    }

    /**
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @param  Collection<int, EpisodeServiceRequest>  $careNeeds
     * @return array{code: string, title: string, message: string, reasons: list<string>}|null
     */
    private static function careFirst(Collection $orientations, bool $careSuggested, bool $medicineSuggested, Collection $careNeeds): ?array
    {
        // Les Soins ont le patient, ou l'ont eu : il n'y a plus rien à rappeler.
        if ($orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Care
            && in_array($orientation->status, [EpisodeOrientationStatus::InProgress, EpisodeOrientationStatus::Completed], true))) {
            return null;
        }

        $carePending = $orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Care
            && $orientation->status === EpisodeOrientationStatus::Pending);
        $careNeedCounts = $careNeeds->isNotEmpty() && ! $medicineSuggested;

        if (! $carePending && ! $careSuggested && ! $careNeedCounts) {
            return null;
        }

        $reasons = [];

        if ($carePending) {
            $reasons[] = 'Il est orienté vers les Soins, qui ne l’ont pas encore pris.';
        }

        if ($careSuggested) {
            $reasons[] = 'L’accueil a suggéré les Soins comme prochaine étape.';
        }

        if ($careNeedCounts) {
            $reasons = [...$reasons, ...self::needReasons($careNeeds)];
        }

        return [
            'code' => self::CARE_FIRST,
            'title' => 'Ce patient devrait d’abord passer aux Soins',
            'message' => 'Les Soins sont prévus avant la consultation (constantes, évaluation). '
                .'Vous pouvez faire les soins vous-même, ou le consulter directement : la décision vous revient.',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @param  Collection<int, EpisodeServiceRequest>  $careNeeds
     * @param  Collection<int, EpisodeServiceRequest>  $medicineNeeds
     * @return array{code: string, title: string, message: string, reasons: list<string>}|null
     */
    private static function medicineOnly(Collection $orientations, bool $careSuggested, bool $medicineSuggested, Collection $careNeeds, Collection $medicineNeeds): ?array
    {
        // Le moindre signal vers les Soins suffit : rien n'est à rappeler.
        if ($careSuggested || $careNeeds->isNotEmpty() || (! $medicineSuggested && $medicineNeeds->isEmpty())) {
            return null;
        }

        // Le médecin a le patient, ou l'a déjà vu : « attendu en Médecine » ne
        // dit plus rien d'utile — un soin après la consultation est ordinaire.
        if ($orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->destination_module === CatalogModule::Medicine
            && in_array($orientation->status, [EpisodeOrientationStatus::InProgress, EpisodeOrientationStatus::Completed], true))) {
            return null;
        }

        $reasons = $medicineSuggested ? ['L’accueil a suggéré la Médecine comme prochaine étape.'] : [];

        return [
            'code' => self::MEDICINE_ONLY,
            'title' => 'Ce patient est attendu en Médecine',
            'message' => 'Il vient pour le médecin : les Soins ne sont pas prévus pour ce passage. '
                .'Vous pouvez quand même le prendre (constantes, soin avant la consultation) : '
                .'il garde sa place en Médecine, et à la fin des soins la suite prévue le conduit chez le médecin.',
            'reasons' => [...$reasons, ...self::needReasons($medicineNeeds)],
        ];
    }

    /**
     * @param  Collection<int, EpisodeServiceRequest>  $requests
     * @return list<string>
     */
    private static function needReasons(Collection $requests): array
    {
        return $requests
            ->map(fn (EpisodeServiceRequest $request): string => "Besoin : {$request->designation} ({$request->routing_mode->label()}).")
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, mixed> */
    private static function relation(Episode $episode, string $name): Collection
    {
        return $episode->relationLoaded($name) ? $episode->getRelation($name) : $episode->{$name}()->get();
    }
}
