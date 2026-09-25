<?php

namespace App\Support;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use Illuminate\Support\Str;

/**
 * ADR-190 — la forme d'une adresse email professionnelle.
 *
 * Le domaine est celui de la clinique, le même sur les trois sites et le
 * portail (`rivo.professional_email.domain`). La partie avant « @ » est
 * proposée depuis la fiche (prenom.nom, sans accents) et reste modifiable :
 * la vraie unicité se vérifie chez l'hébergeur, où les trois sites partagent
 * le même domaine.
 */
final class ProfessionalEmailAddress
{
    /** Lettres, chiffres, point, tiret et soulignement ; ni au début, ni à la fin, jamais deux points de suite. */
    public const LOCAL_PART_PATTERN = '/^(?!.*\.\.)[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

    public const LOCAL_PART_MESSAGE = 'Seuls les lettres sans accent, les chiffres, le point, le tiret et le soulignement sont acceptés, sans point au début ni à la fin (64 caractères au plus).';

    public static function domain(): string
    {
        return mb_strtolower(trim((string) config('rivo.professional_email.domain')));
    }

    public static function configured(): bool
    {
        return self::domain() !== '';
    }

    public static function compose(string $localPart): string
    {
        return mb_strtolower(trim($localPart)).'@'.self::domain();
    }

    public static function isValidLocalPart(string $localPart): bool
    {
        return preg_match(self::LOCAL_PART_PATTERN, $localPart) === 1;
    }

    /** « Zéphyr Haynes » → « zephyr.haynes ». */
    public static function slug(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower($value));
        $slug = preg_replace('/[^a-z0-9]+/', '.', $ascii) ?? '';

        return mb_substr(trim($slug, '.'), 0, 64);
    }

    /**
     * La partie avant « @ » proposée pour un employé : prenom.nom, suivie d'un
     * chiffre si l'adresse est déjà prise sur ce site (une autre adresse
     * ouverte, ou l'email d'une autre fiche).
     */
    public static function suggest(Employee $employee): string
    {
        $base = self::slug(trim($employee->first_name.' '.$employee->last_name))
            ?: self::slug((string) $employee->employee_number)
            ?: 'employe';

        $candidate = $base;
        for ($suffix = 2; self::takenOnSite($candidate, $employee) && $suffix < 100; $suffix++) {
            $candidate = mb_substr($base, 0, 60).$suffix;
        }

        return $candidate;
    }

    private static function takenOnSite(string $localPart, Employee $employee): bool
    {
        $address = self::compose($localPart);

        return ProfessionalMailbox::query()
            ->where('address', $address)
            ->whereIn('status', ProfessionalMailboxStatus::openValues())
            ->exists()
            || Employee::withTrashed()
                ->whereKeyNot($employee->getKey())
                ->whereRaw('LOWER(email) = ?', [$address])
                ->exists();
    }
}
