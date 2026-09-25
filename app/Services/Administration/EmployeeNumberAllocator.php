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
 */
class EmployeeNumberAllocator
{
    public function __construct(private readonly AppSettings $settings) {}

    public function suggest(): string
    {
        return $this->sequence(1)[0];
    }

    /**
     * Les `$count` prochains matricules, en sautant ceux déjà pris et ceux qu'un
     * même import réserve déjà.
     *
     * @param  array<int, string>  $reserved
     * @return array<int, string>
     */
    public function sequence(int $count, array $reserved = []): array
    {
        $format = $this->format();
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

    public function format(): EmployeeNumberFormat
    {
        return $this->settings->employeeNumbering();
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
