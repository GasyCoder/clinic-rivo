<?php

namespace App\Support;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use Illuminate\Support\Collection;

/**
 * ADR-177, amendement du 2026-09-27 — un patient déjà pris en charge en
 * Médecine ou aux Soins ne se prend pas une seconde fois depuis le tableau des
 * passages d'un autre service.
 *
 * Tous les services voient tous les passages (ADR-177) : sans cette règle, une
 * infirmière pouvait « prendre » la patiente que le médecin avait en
 * consultation, et deux services se retrouvaient responsables du même patient
 * au même moment. Le bouton devient « En cours », et le serveur refuse.
 *
 * ```text
 * tient le patient      une orientation Médecine ou Soins EN COURS, d'un autre service
 * ne bloque jamais      une demande adressée à ce service (ordre de soins du médecin,
 *                       transmission, envoi aux Soins) : le travail parallèle y est voulu
 *                       (ADR-055, ADR-088) ; une urgence (ADR-021) ; le service qui a
 *                       déjà le patient (on retrouve sa prise en charge)
 * ```
 *
 * La Maternité, l'Hospitalisation et les examens ne tiennent pas le patient au
 * sens de cette règle : la demande ne les nomme pas, et une patiente
 * hospitalisée ou suivie en Maternité doit pouvoir être vue par ailleurs.
 */
final class EpisodeHeldElsewhere
{
    /** Les services dont une prise en charge en cours tient le patient. */
    public const HOLDERS = [CatalogModule::Medicine, CatalogModule::Care];

    /**
     * L'orientation d'un autre service qui a le patient en ce moment, ou `null`.
     *
     * @param  Collection<int, EpisodeOrientation>|null  $orientations  celles du passage, déjà chargées
     */
    public static function holder(Episode $episode, CatalogModule $module, ?Collection $orientations = null): ?EpisodeOrientation
    {
        if ($episode->priority === EpisodePriority::Emergency) {
            return null;
        }

        $orientations ??= $episode->orientations()->with('acceptedBy:id,name')->get();

        // Une demande adressée à ce service se prend toujours.
        $requested = $orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->destination_module === $module
            && $orientation->status === EpisodeOrientationStatus::Pending);

        if ($requested) {
            return null;
        }

        return $orientations->first(fn (EpisodeOrientation $orientation) => $orientation->destination_module !== $module
            && in_array($orientation->destination_module, self::HOLDERS, true)
            && $orientation->status === EpisodeOrientationStatus::InProgress);
    }

    /** « en Médecine », « aux Soins » */
    public static function where(CatalogModule $module): string
    {
        return $module === CatalogModule::Care ? 'aux Soins' : 'en '.$module->label();
    }

    public static function message(EpisodeOrientation $holder): string
    {
        $by = $holder->acceptedBy?->name;
        $since = $holder->accepted_at?->format('H:i');

        return 'Ce patient est déjà pris en charge '.self::where($holder->destination_module)
            .($by ? " par {$by}" : '')
            .($since ? " depuis {$since}" : '')
            .'. Il reste visible ici, mais ne se prend pas une seconde fois : il reviendra dans votre file par une transmission ou une demande adressée à votre service.';
    }

    /**
     * Ce que la ligne du tableau en dit.
     *
     * @return array{module: string, label: string, where: string, by: ?string, since: mixed, message: string}
     */
    public static function present(EpisodeOrientation $holder): array
    {
        return [
            'module' => $holder->destination_module->value,
            'label' => $holder->destination_module->label(),
            'where' => self::where($holder->destination_module),
            'by' => $holder->acceptedBy?->name,
            'since' => $holder->accepted_at,
            'message' => self::message($holder),
        ];
    }
}
