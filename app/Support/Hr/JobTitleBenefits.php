<?php

namespace App\Support\Hr;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;

/**
 * ADR-221 — qui peut recevoir un avantage ou une prime se règle par fonction,
 * dans le module Fonctions (métadonnée `benefits_eligible`). Arbitrage du
 * propriétaire : « Médecin » ouvre droit d'office ; rien n'est codé en dur
 * ailleurs, et une décision déjà prise n'est jamais réécrite.
 */
final class JobTitleBenefits
{
    public const KEY = 'benefits_eligible';

    /** Les fonctions livrées qui ouvrent droit aux avantages. */
    public const DEFAULT_CODES = ['DOCTOR'];

    /**
     * Range `benefits_eligible` dans les métadonnées ; `null` quand la clé n'est pas envoyée.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $metadata
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    public static function takeFrom(array $data, ?array $metadata): array
    {
        if (! array_key_exists(self::KEY, $data)) {
            return [$data, null];
        }

        $eligible = (bool) $data[self::KEY];
        unset($data[self::KEY]);

        return [$data, [...($metadata ?? []), self::KEY => $eligible]];
    }

    /** Coche les fonctions livrées, sauf celles sur lesquelles une décision existe déjà. */
    public static function apply(): void
    {
        HrReferenceValue::withTrashed()->ofType(HrReferenceType::JobTitle)
            ->whereIn('code', self::DEFAULT_CODES)->get()
            ->each(function (HrReferenceValue $jobTitle): void {
                $metadata = $jobTitle->metadata ?? [];

                if (! array_key_exists(self::KEY, $metadata)) {
                    $jobTitle->forceFill(['metadata' => [...$metadata, self::KEY => true]])->saveQuietly();
                }
            });
    }
}
