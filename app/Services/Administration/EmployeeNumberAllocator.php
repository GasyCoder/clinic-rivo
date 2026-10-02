<?php

namespace App\Services\Administration;

use App\Models\Employee;
use App\Services\Settings\AppSettings;
use App\Support\Numbering\EmployeeNumberFormat;

/**
 * ADR-191 — le matricule proposé à la création d'un employé.
 *
 * Le prochain numéro suit le plus grand matricule déjà écrit selon le modèle du
 * site, archives comprises : un matricule n'est jamais redonné. Un matricule saisi
 * autrement (« RH-001 ») ne compte pas, il ne suit pas le modèle. Rien n'est
 * réservé à l'affichage : c'est une proposition, que le RH peut corriger.
 *
 * ADR-243 — les stagiaires ont leur propre série (`STG-0001`), réglée à côté de
 * celle des employés : un stagiaire ne prend jamais le prochain matricule d'un
 * employé, et les deux séries ne se disputent aucun numéro.
 */
class EmployeeNumberAllocator
{
    public function __construct(private readonly AppSettings $settings) {}

    public function suggest(bool $intern = false): string
    {
        return $this->sequence(1, intern: $intern)[0];
    }

    /**
     * Les `$count` prochains matricules, en sautant ceux déjà pris et ceux qu'un
     * même import réserve déjà.
     *
     * @param  array<int, string>  $reserved
     * @return array<int, string>
     */
    public function sequence(int $count, array $reserved = [], bool $intern = false): array
    {
        $format = $this->format($intern);
        $taken = array_map(fn (string $number) => mb_strtoupper(trim($number)), $reserved);
        $counter = max($this->highest($format), ...array_map(fn (string $number) => $format->counterOf($number) ?? 0, $reserved ?: ['']));
        $numbers = [];

        while (count($numbers) < $count) {
            $candidate = $format->format(++$counter);

            if (! in_array(mb_strtoupper($candidate), $taken, true)
                && ! Employee::withTrashed()->where('employee_number', $candidate)->exists()) {
                $numbers[] = $candidate;
            }
        }

        return $numbers;
    }

    /**
     * Le matricule de la feuille du personnel : H (homme) ou F (femme), l'année d'entrée
     * puis le jour et le mois de naissance — « F20151808 ». Rien n'est deviné : sans sexe,
     * date d'entrée et date de naissance, ou si ce numéro est déjà pris, null.
     *
     * @param  array<int, string>  $reserved
     */
    public function fromProfile(?string $sex, ?string $hireDate, ?string $birthDate, array $reserved = []): ?string
    {
        if (! in_array($sex, ['M', 'F'], true) || ! $hireDate || ! $birthDate) {
            return null;
        }

        try {
            $hired = \Carbon\CarbonImmutable::parse($hireDate);
            $born = \Carbon\CarbonImmutable::parse($birthDate);
        } catch (\Throwable) {
            return null;
        }

        $number = ($sex === 'M' ? 'H' : 'F').$hired->format('Y').$born->format('dm');
        $taken = array_map(fn (string $value) => mb_strtoupper(trim($value)), $reserved);

        if (in_array($number, $taken, true) || Employee::withTrashed()->where('employee_number', $number)->exists()) {
            return null;
        }

        return $number;
    }

    public function format(bool $intern = false): EmployeeNumberFormat
    {
        return $intern ? $this->settings->internNumbering() : $this->settings->employeeNumbering();
    }

    private function highest(EmployeeNumberFormat $format): int
    {
        return (int) Employee::withTrashed()
            ->where('employee_number', 'like', $format->prefix.'%')
            ->pluck('employee_number')
            ->map(fn (string $number) => $format->counterOf($number) ?? 0)
            ->max();
    }
}
