<?php

namespace App\Support\Documents;

use App\Enums\DocumentDataContext;
use Illuminate\Support\Str;

/**
 * ADR-199 — les dossiers de documents : un par type de document (CONTRAT,
 * CONGE, ATTESTATION…). Le type reste un libellé libre du Super Admin
 * (ADR-070) ; le dossier se lit sur lui, sans accents ni casse, pour que
 * « Congé » et « CONGE » tombent dans le même dossier. Un type inconnu a son
 * propre dossier : rien ne disparaît.
 *
 * Les mêmes dossiers servent au portail (les canevas) et au site (les documents
 * produits), et `resources/js/utilities/documentFamilies.js` en garde les icônes.
 */
final class DocumentFamily
{
    /** @var array<string, array{label: string, context: DocumentDataContext}> */
    public const FAMILIES = [
        'CONTRAT' => ['label' => 'Contrats', 'context' => DocumentDataContext::EmployeeAndContract],
        'CONGE' => ['label' => 'Congés', 'context' => DocumentDataContext::EmployeeAndLeave],
        'ATTESTATION' => ['label' => 'Attestations', 'context' => DocumentDataContext::EmployeeOnly],
        'CERTIFICAT' => ['label' => 'Certificats', 'context' => DocumentDataContext::EmployeeOnly],
        'LETTRE' => ['label' => 'Lettres', 'context' => DocumentDataContext::EmployeeOnly],
        'DECISION' => ['label' => 'Décisions', 'context' => DocumentDataContext::EmployeeOnly],
        'AUTRE' => ['label' => 'Autres', 'context' => DocumentDataContext::EmployeeOnly],
    ];

    /** Le dossier d'un type : sans accents, en capitales ; vide → AUTRE. */
    public static function key(?string $type): string
    {
        $key = Str::of((string) $type)->ascii()->upper()->trim()->replaceMatches('/\s+/', ' ')->toString();

        return $key === '' ? 'AUTRE' : $key;
    }

    public static function label(string $key): string
    {
        return self::FAMILIES[$key]['label'] ?? Str::of($key)->lower()->ucfirst()->toString();
    }

    /** Le contexte de données attendu pour ce dossier (un contrat pour « Contrats »…). */
    public static function context(string $key): DocumentDataContext
    {
        return self::FAMILIES[$key]['context'] ?? DocumentDataContext::EmployeeOnly;
    }

    /**
     * Les dossiers à montrer : les sept connus, puis ceux qu'un type libre a créés.
     *
     * @param  iterable<string|null>  $types
     * @return list<string>
     */
    public static function keys(iterable $types): array
    {
        $extra = collect($types)->map(fn ($type) => self::key($type))
            ->reject(fn (string $key) => array_key_exists($key, self::FAMILIES))
            ->unique()->sort()->values()->all();

        return [...array_keys(self::FAMILIES), ...$extra];
    }

    /**
     * Les types écrits qui tombent dans ce dossier (pour filtrer en base, où le
     * type est gardé tel que saisi).
     *
     * @param  iterable<string|null>  $types
     * @return list<string>
     */
    public static function typesIn(string $key, iterable $types): array
    {
        return collect($types)->filter(fn ($type) => self::key($type) === $key)->unique()->values()->all();
    }
}
