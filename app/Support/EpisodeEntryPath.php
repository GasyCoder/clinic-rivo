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
 * Médecine prend un patient attendu aux Soins   CARE_FIRST   on le dit, le médecin décide
 * Soins prend un patient attendu en Médecine    MEDICINE_ONLY on le dit, et on refuse
 * ```
 *
 * Le refus aux Soins applique l'ADR-030 — une prestation MEDICINE_DIRECT ne
 * passe pas artificiellement par les Soins — et ne vaut que si **rien** ne
 * désigne les Soins. Un soin demandé par le médecin (une vraie orientation
 * vers les Soins, en attente) et l'urgence (ADR-021) n'y sont jamais soumis.
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
     * ne s'oppose à ce qu'il le prenne. Les relations `serviceRequests`,
     * `receptionNextSteps` et `orientations` sont lues chargées si elles le
     * sont, interrogées sinon.
     *
     * @return array{code: string, blocking: bool, title: string, message: string, reasons: list<string>}|null
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

        return self::medicineOnly($careSuggested, $medicineSuggested, $careNeeds, $medicineNeeds);
    }

    /**
     * Le refus que les Soins opposent, écrit une fois pour l'action et l'écran.
     */
    public static function refusalMessage(): string
    {
        return 'Ce patient est attendu directement en Médecine : il ne se prend pas en charge aux Soins. '
            .'Un soin se fait ici à la demande du médecin, ou dès que le passage est classé en urgence.';
    }

    /**
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @param  Collection<int, EpisodeServiceRequest>  $careNeeds
     * @return array{code: string, blocking: bool, title: string, message: string, reasons: list<string>}|null
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
            'blocking' => false,
            'title' => 'Ce patient devrait d’abord passer aux Soins',
            'message' => 'Les Soins sont prévus avant la consultation (constantes, évaluation). '
                .'Vous pouvez faire les soins vous-même, ou le consulter directement : la décision vous revient.',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  Collection<int, EpisodeServiceRequest>  $careNeeds
     * @param  Collection<int, EpisodeServiceRequest>  $medicineNeeds
     * @return array{code: string, blocking: bool, title: string, message: string, reasons: list<string>}|null
     */
    private static function medicineOnly(bool $careSuggested, bool $medicineSuggested, Collection $careNeeds, Collection $medicineNeeds): ?array
    {
        // Le moindre signal vers les Soins suffit : on ne refuse que ce qui
        // n'y a clairement rien à faire.
        if ($careSuggested || $careNeeds->isNotEmpty() || (! $medicineSuggested && $medicineNeeds->isEmpty())) {
            return null;
        }

        $reasons = $medicineSuggested ? ['L’accueil a suggéré la Médecine comme prochaine étape.'] : [];

        return [
            'code' => self::MEDICINE_ONLY,
            'blocking' => true,
            'title' => 'Ce patient est attendu en Médecine',
            'message' => 'Il va directement chez le médecin et ne passe pas par les Soins. '
                .'Un soin se fait ici à la demande du médecin, ou dès que le passage est classé en urgence.',
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
