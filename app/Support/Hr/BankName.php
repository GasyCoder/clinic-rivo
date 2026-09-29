<?php

namespace App\Support\Hr;

use App\Models\Bank;
use Illuminate\Support\Str;

/**
 * ADR-221 — reconnaître deux saisies d'une même banque.
 *
 * « Bank of Africa », « BANK OF AFRICA » et « Bank of Africa Madagascar » sont
 * une seule banque, « BOA Madagascar » aussi quand BOA existe. La règle est
 * volontairement prudente : elle refuse ce qui ressemble à un doublon et dit
 * lequel, plutôt que de laisser la liste se dédoubler.
 */
final class BankName
{
    /** Ce qui distingue un nom : les mots, sans accents, casse ni ponctuation. */
    public static function normalize(?string $value): string
    {
        return Str::of((string) $value)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', ' ')->squish()->toString();
    }

    public static function clean(?string $value): string
    {
        return Str::squish((string) $value);
    }

    /** Le code court : BOA, BNI, BMOI — majuscules, lettres et chiffres. */
    public static function code(?string $value): string
    {
        return Str::of((string) $value)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->limit(20, '')->toString();
    }

    /**
     * La banque existante (archivée comprise) que ce code ou ce nom désigne déjà.
     */
    public static function duplicateOf(string $code, string $name, ?Bank $ignore = null): ?Bank
    {
        $code = self::code($code);
        $normalized = self::normalize($name);
        $words = explode(' ', $normalized);

        return Bank::withTrashed()
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->get(['id', 'uuid', 'code', 'name', 'normalized_name', 'deleted_at'])
            ->first(function (Bank $bank) use ($code, $normalized, $words): bool {
                $other = $bank->normalized_name;

                return $bank->code === $code
                    || $other === $normalized
                    // « BOA » ou « BOA MADAGASCAR » quand BOA existe.
                    || $normalized === $bank->code
                    || $words[0] === $bank->code
                    || $other === $code
                    // « BANK OF AFRICA » face à « BANK OF AFRICA MADAGASCAR », dans un sens ou l'autre.
                    || ($normalized !== '' && (str_starts_with($other.' ', $normalized.' ') || str_starts_with($normalized.' ', $other.' ')));
            });
    }
}
