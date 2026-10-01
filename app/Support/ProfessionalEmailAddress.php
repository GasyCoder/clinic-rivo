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
 *
 * Un prénom et un nom trop longs donnent une adresse brève (ADR-190,
 * amendement du 2026-09-30) : la forme la plus complète qui tient en
 * PREFERRED_LENGTH caractères, sinon la plus courte.
 */
final class ProfessionalEmailAddress
{
    /** Lettres, chiffres, point, tiret et soulignement ; ni au début, ni à la fin, jamais deux points de suite. */
    public const LOCAL_PART_PATTERN = '/^(?!.*\.\.)[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

    /** Au-delà, prénom + nom se raccourcit (« latifah.olsen.lee.park » → « latifah.lee »). */
    public const PREFERRED_LENGTH = 20;

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
     * La partie avant « @ » d'un prénom et d'un nom, sans accents. Les formes
     * proposées, de la plus complète à la plus brève :
     *
     *   latifah.olsen.lee.park   tous les mots, si c'est assez court
     *   latifah.lee              le premier prénom et le premier nom
     *   l.lee                    l'initiale du prénom et le premier nom
     *   latifah.l                le premier prénom et l'initiale du nom
     *
     * La première qui tient en PREFERRED_LENGTH caractères est retenue ; si
     * aucune ne tient, la plus courte. Vide quand le nom ne donne aucun mot.
     */
    public static function localPartFor(?string $firstName, ?string $lastName): string
    {
        $first = self::words($firstName);
        $last = self::words($lastName);

        if ($first === [] || $last === []) {
            $only = $first ?: $last;
            $candidates = $only === [] ? [] : [
                implode('.', $only),
                implode('.', array_slice($only, 0, 2)),
                $only[0],
            ];
        } else {
            $candidates = [
                implode('.', $first).'.'.implode('.', $last),
                $first[0].'.'.$last[0],
                mb_substr($first[0], 0, 1).'.'.$last[0],
                $first[0].'.'.mb_substr($last[0], 0, 1),
            ];
        }

        $candidates = array_values(array_unique(array_map(fn (string $candidate) => mb_substr($candidate, 0, 64), $candidates)));
        if ($candidates === []) {
            return '';
        }

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) <= self::PREFERRED_LENGTH) {
                return $candidate;
            }
        }

        usort($candidates, fn (string $a, string $b) => mb_strlen($a) <=> mb_strlen($b));

        return $candidates[0];
    }

    /**
     * La partie avant « @ » proposée pour un employé (voir localPartFor),
     * suivie d'un chiffre si l'adresse est déjà prise sur ce site (une autre
     * adresse ouverte, ou l'email d'une autre fiche).
     */
    public static function suggest(Employee $employee): string
    {
        $base = self::localPartFor($employee->first_name, $employee->last_name)
            ?: self::slug((string) $employee->employee_number)
            ?: 'employe';

        $candidate = $base;
        for ($suffix = 2; self::takenOnSite($candidate, $employee) && $suffix < 100; $suffix++) {
            $candidate = mb_substr($base, 0, 60).$suffix;
        }

        return $candidate;
    }

    /** « Lee-Park » → ['lee', 'park'] : minuscules, sans accents. */
    private static function words(?string $value): array
    {
        $ascii = Str::ascii(mb_strtolower((string) $value));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $ascii) ?: [], fn (string $word) => $word !== ''));
    }

    private static function takenOnSite(string $localPart, Employee $employee): bool
    {
        $address = self::compose($localPart);

        // Sa propre boîte n'est pas « prise » : elle est à lui.
        return ProfessionalMailbox::query()
            ->where('address', $address)
            ->whereIn('status', ProfessionalMailboxStatus::openValues())
            ->when($employee->getKey(), fn ($query, $id) => $query->where('employee_id', '!=', $id))
            ->exists()
            || Employee::withTrashed()
                ->whereKeyNot($employee->getKey())
                ->whereRaw('LOWER(email) = ?', [$address])
                ->exists();
    }
}
