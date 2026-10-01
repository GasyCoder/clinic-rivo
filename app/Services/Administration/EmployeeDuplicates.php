<?php

namespace App\Services\Administration;

use App\Models\Employee;
use Illuminate\Support\Str;

/**
 * ADR-236 — les dossiers en service qui désignent peut-être la même personne : même nom
 * et même prénom (sans accents, casse ni espaces doubles), et, quand les deux dates sont
 * connues, la même date de naissance. Un repère, jamais une décision : deux homonymes
 * existent, et seul le RH sait lequel garder.
 *
 * Calculé en mémoire sur les dossiers non archivés d'un site — quelques centaines au plus —
 * pour comparer les noms comme un humain les lit, ce que SQL ne sait pas faire sans accents.
 */
class EmployeeDuplicates
{
    /** @var array<int, list<array{uuid: string, number: string|null, name: string}>>|null */
    private ?array $groups = null;

    /**
     * Pour chaque dossier en double, les autres dossiers de la même personne.
     *
     * @return array<int, list<array{uuid: string, number: string|null, name: string}>>
     */
    public function all(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $employees = Employee::query()
            ->get(['id', 'uuid', 'employee_number', 'first_name', 'last_name', 'birth_date']);

        $byName = $employees->groupBy(fn (Employee $employee) => self::key($employee))
            ->filter(fn ($group, $key) => $key !== '' && $group->count() > 1);

        $groups = [];
        foreach ($byName as $group) {
            foreach ($group as $employee) {
                $others = $group->filter(fn (Employee $other) => $other->isNot($employee) && self::sameBirth($employee, $other));
                if ($others->isNotEmpty()) {
                    $groups[$employee->getKey()] = $others->map(fn (Employee $other) => [
                        'uuid' => $other->uuid,
                        'number' => $other->employee_number,
                        'name' => trim("{$other->last_name} {$other->first_name}"),
                    ])->values()->all();
                }
            }
        }

        return $this->groups = $groups;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /** @return list<array{uuid: string, number: string|null, name: string}> */
    public function of(Employee $employee): array
    {
        return $this->all()[$employee->getKey()] ?? [];
    }

    public static function key(Employee $employee): string
    {
        $last = Str::of(Str::ascii((string) $employee->last_name))->lower()->squish()->toString();
        $first = Str::of(Str::ascii((string) $employee->first_name))->lower()->squish()->toString();

        return $last === '' ? '' : "{$last}|{$first}";
    }

    private static function sameBirth(Employee $a, Employee $b): bool
    {
        return $a->birth_date === null || $b->birth_date === null
            || $a->birth_date->isSameDay($b->birth_date);
    }
}
