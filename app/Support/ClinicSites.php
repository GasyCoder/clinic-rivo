<?php

namespace App\Support;

/**
 * Les autres sites de la Clinique Saint Georges, vus depuis le site courant.
 *
 * Proposés comme destinations d'un transfert : depuis Ambondromamy, Mampikony
 * et Boriziny ; depuis Mampikony, Ambondromamy et Boriziny. Écrit une seule
 * fois — la consultation, le module Transferts et le séjour le lisent tous
 * les trois, et trois copies finiraient par proposer trois listes.
 *
 * Lu dans la configuration (`rivo.clinics`), jamais dans une base : chaque
 * site a la sienne (ADR-001, ADR-025), et la liste des sites est la même
 * partout. Le libellé est celui qui part sur la demande et la lettre de
 * référence ; un établissement extérieur reste une saisie libre.
 */
final class ClinicSites
{
    /**
     * @return list<array{code: string, name: string, destination: string}>
     */
    public static function others(): array
    {
        $current = strtoupper((string) config('rivo.site.code'));

        return collect(config('rivo.clinics', []))
            ->filter(fn (array $site) => strtoupper((string) ($site['code'] ?? '')) !== $current)
            ->map(fn (array $site) => [
                'code' => (string) $site['code'],
                'name' => (string) $site['name'],
                'destination' => self::destination((string) $site['name']),
            ])
            ->values()
            ->all();
    }

    /** Le libellé d'un site comme destination d'un transfert. */
    public static function destination(string $name): string
    {
        return 'Clinique Saint Georges — '.$name;
    }
}
