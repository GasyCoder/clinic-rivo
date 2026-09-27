<?php

namespace App\Support;

/**
 * Ce qu'un acte Maternité laisse attendre du dossier (ADR-136).
 *
 * Le dossier Maternité est le même pour tout acte : six sections, dont la
 * sage-femme n'a besoin que de deux ou trois selon ce que la Réception a
 * demandé. Ce profil dit **lesquelles mettre en avant** — jamais lesquelles
 * interdire : toutes les sections que le compte a le droit d'écrire restent
 * atteignables, et aucun champ n'est ajouté ni exigé par acte.
 *
 * C'est une aide de navigation, pas une règle clinique : le CDC ne décrit
 * aucun contenu par acte, et il n'en est donc pas inventé. Un code absent de
 * ce tableau (un acte ajouté au catalogue après coup, « Nursie » dont le sens
 * n'est pas défini, « IEC », « Autres ») ne met que les actes en avant. Le
 * code est la seule clé : rien n'est déduit d'un libellé (ADR-052).
 */
final class MaternityActProfile
{
    /** L'ordre dans lequel les sections s'enchaînent au chevet de la patiente. */
    public const SECTIONS = ['context', 'prenatal', 'labor', 'delivery', 'newborn', 'procedures'];

    /** Le code du catalogue qui exige une précision (« Autre acte de maternité, à préciser »). */
    public const OTHER_CODE = 'MAT-OTHER';

    /** Décision de Maternité qui ne s'enregistre jamais comme acte : elle part à Chirurgie (ADR-067). */
    public const CESAREAN_CODES = ['MAT-CESAREAN-SIMPLE', 'MAT-CESAREAN-TWIN'];

    /** @var array<string, array{sections: list<string>, newborns?: int}> */
    private const PROFILES = [
        'MAT-CONSULT-PRENATAL' => ['sections' => ['context', 'prenatal']],
        'MAT-CONSULT-PRENATAL-SUIVI' => ['sections' => ['context', 'prenatal']],
        'MAT-DOPPLER' => ['sections' => ['prenatal']],
        'MAT-DELIVERY-SIMPLE' => ['sections' => ['context', 'labor', 'delivery', 'newborn'], 'newborns' => 1],
        'MAT-DELIVERY-TWIN' => ['sections' => ['context', 'labor', 'delivery', 'newborn'], 'newborns' => 2],
        'MAT-UMBILICAL-DRESSING' => ['sections' => ['newborn']],
        'MAT-BABY-WEIGHT' => ['sections' => ['newborn']],
        'MAT-BABY-CARE' => ['sections' => ['newborn']],
        'MAT-BABY-ASPIRATOR' => ['sections' => ['newborn']],
        'MAT-PHOTOTHERAPY' => ['sections' => ['newborn']],
    ];

    /**
     * @param  iterable<string>  $codes  les codes des actes demandés à la Réception
     * @return array{sections: list<string>, expected_newborns: ?int}
     */
    public static function forCodes(iterable $codes): array
    {
        $codes = collect($codes)->filter()->unique()->values();
        $known = $codes->filter(fn (string $code): bool => isset(self::PROFILES[$code]));

        // Des actes sont demandés : la section des actes l'est toujours, c'est
        // là qu'ils s'enregistrent. Sans acte demandé, rien n'est mis en avant.
        if ($codes->isEmpty()) {
            return ['sections' => [], 'expected_newborns' => null];
        }

        $wanted = $known->flatMap(fn (string $code): array => self::PROFILES[$code]['sections'])
            ->push('procedures')
            ->unique();

        return [
            'sections' => array_values(array_filter(self::SECTIONS, fn (string $section): bool => $wanted->contains($section))),
            // Un accouchement gémellaire annonce deux enfants : deux fiches vides
            // s'ouvrent, jamais deux fiches remplies.
            'expected_newborns' => $known->map(fn (string $code): ?int => self::PROFILES[$code]['newborns'] ?? null)
                ->filter()->max(),
        ];
    }
}
