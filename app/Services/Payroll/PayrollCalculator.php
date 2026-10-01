<?php

namespace App\Services\Payroll;

use App\Enums\EmployeeRemunerationType;
use App\Support\Money;

/**
 * ADR-233 — les retenues légales d'une paie, calculées sur des règles reçues (les
 * paramètres du site, ou un brouillon pour la simulation) : rien n'est écrit en dur ici.
 *
 *   CNAPS salarié      = taux × min(brut, plafond)            arrondi à l'ariary
 *   organisme médical  = taux × min(brut, plafond)            arrondi à l'ariary
 *   base imposable     = brut − CNAPS − organisme médical     arrondie vers le bas si réglé
 *   IRSA               = somme des tranches × leur taux, moins la réduction par enfant,
 *                        jamais sous le minimum quand la base dépasse la tranche à 0 %
 *   charges patronales = mêmes bases, taux employeur — information, jamais retenues
 *
 * Tout se compte en centimes entiers (`Money`) : aucun flottant ne touche un montant.
 */
final class PayrollCalculator
{
    public const DEDUCTION_KINDS = ['CNAPS', 'HEALTH', 'IRSA'];

    /**
     * @param  array<string, mixed>  $rules  `PayrollSetting::snapshot()`
     * @return array{applies: bool, reason: ?string, gross: int, cnaps: int, health: int, taxable: int, irsa_before_reduction: int, child_reduction: int, irsa: int, children: int, employer_cnaps: int, employer_health: int}
     */
    public function compute(int $grossMinor, ?EmployeeRemunerationType $type, int $children, array $rules): array
    {
        $result = [
            'applies' => false, 'reason' => null, 'gross' => max(0, $grossMinor),
            'cnaps' => 0, 'health' => 0, 'taxable' => max(0, $grossMinor),
            'irsa_before_reduction' => 0, 'child_reduction' => 0, 'irsa' => 0, 'children' => max(0, $children),
            'employer_cnaps' => 0, 'employer_health' => 0,
        ];

        $reason = $this->exemption($type, $rules);
        if ($reason !== null || $grossMinor <= 0) {
            $result['reason'] = $reason ?? 'Aucun montant ce mois-ci.';

            return $result;
        }

        $result['applies'] = true;
        $cnapsBase = $this->capped($grossMinor, $rules['cnaps_ceiling'] ?? null);
        $healthBase = $this->capped($grossMinor, $rules['health_ceiling'] ?? null);

        $result['cnaps'] = $this->share($cnapsBase, $rules['cnaps_employee_rate'] ?? 0);
        $result['health'] = $this->share($healthBase, $rules['health_employee_rate'] ?? 0);
        $result['employer_cnaps'] = $this->share($cnapsBase, $rules['cnaps_employer_rate'] ?? 0);
        $result['employer_health'] = $this->share($healthBase, $rules['health_employer_rate'] ?? 0);

        $taxable = max(0, $grossMinor - $result['cnaps'] - $result['health']);
        $step = (int) ($rules['irsa_base_rounding'] ?? 0);
        if ($step > 0) {
            $taxable -= $taxable % ($step * 100);
        }
        $result['taxable'] = $taxable;

        $tax = $this->progressive($taxable, $rules['irsa_brackets'] ?? []);
        $result['irsa_before_reduction'] = $tax;

        if ($tax > 0) {
            $reduction = $result['children'] * Money::toMinor((string) ($rules['irsa_child_reduction'] ?? '0'));
            $minimum = Money::toMinor((string) ($rules['irsa_minimum'] ?? '0'));
            $result['child_reduction'] = min($reduction, max(0, $tax - $minimum));
            $result['irsa'] = max($tax - $reduction, $minimum);
        }

        return $result;
    }

    /**
     * Les lignes de retenue que la paie porte (montants négatifs, comme les dettes).
     *
     * @param  array<string, mixed>  $result  `compute()`
     * @param  array<string, mixed>  $rules
     * @return list<array{kind: string, label: string, amount: string, uuid: null}>
     */
    public function lines(array $result, array $rules): array
    {
        if (! $result['applies']) {
            return [];
        }

        $lines = [];
        if ($result['cnaps'] > 0) {
            $lines[] = ['kind' => 'CNAPS', 'label' => 'CNAPS — '.$this->rate($rules['cnaps_employee_rate'] ?? 0).$this->ceilingNote($rules['cnaps_ceiling'] ?? null), 'amount' => Money::fromMinor(-$result['cnaps']), 'uuid' => null];
        }
        if ($result['health'] > 0) {
            $label = filled($rules['health_label'] ?? null) ? $rules['health_label'] : 'Organisme médical';
            $lines[] = ['kind' => 'HEALTH', 'label' => $label.' — '.$this->rate($rules['health_employee_rate'] ?? 0).$this->ceilingNote($rules['health_ceiling'] ?? null), 'amount' => Money::fromMinor(-$result['health']), 'uuid' => null];
        }
        if ($result['irsa'] > 0) {
            $detail = 'base imposable '.$this->ariary($result['taxable']);
            if ($result['child_reduction'] > 0) {
                $detail .= ' · réduction '.$result['children'].' enfant'.($result['children'] > 1 ? 's' : '').' '.$this->ariary($result['child_reduction']);
            }
            $lines[] = ['kind' => 'IRSA', 'label' => "IRSA — {$detail}", 'amount' => Money::fromMinor(-$result['irsa']), 'uuid' => null];
        }

        return $lines;
    }

    /**
     * Les charges patronales, pour information (ne changent pas le net).
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $rules
     * @return list<array{kind: string, label: string, amount: string}>
     */
    public function employerLines(array $result, array $rules): array
    {
        if (! $result['applies']) {
            return [];
        }

        $lines = [];
        if ($result['employer_cnaps'] > 0) {
            $lines[] = ['kind' => 'EMPLOYER_CNAPS', 'label' => 'CNAPS employeur — '.$this->rate($rules['cnaps_employer_rate'] ?? 0), 'amount' => Money::fromMinor($result['employer_cnaps'])];
        }
        if ($result['employer_health'] > 0) {
            $label = filled($rules['health_label'] ?? null) ? $rules['health_label'] : 'Organisme médical';
            $lines[] = ['kind' => 'EMPLOYER_HEALTH', 'label' => $label.' employeur — '.$this->rate($rules['health_employer_rate'] ?? 0), 'amount' => Money::fromMinor($result['employer_health'])];
        }

        return $lines;
    }

    /** Pourquoi une personne n'a pas de retenue légale ce mois-ci, ou `null`. */
    public function exemption(?EmployeeRemunerationType $type, array $rules): ?string
    {
        if (! ($rules['legal_deductions_enabled'] ?? false)) {
            return 'Retenues légales non activées dans les paramètres de paie.';
        }
        if ($type === EmployeeRemunerationType::Unpaid) {
            return 'Non rémunéré : aucune retenue.';
        }
        if ($type === EmployeeRemunerationType::Allowance && ! ($rules['allowance_subject'] ?? false)) {
            return 'Indemnité de stage : non soumise aux retenues (paramètres de paie).';
        }

        return null;
    }

    private function progressive(int $taxableMinor, array $brackets): int
    {
        $tax = 0;
        $lower = 0;

        foreach ($brackets as $bracket) {
            $upper = isset($bracket['up_to']) && $bracket['up_to'] !== null && $bracket['up_to'] !== '' ? Money::toMinor((string) $bracket['up_to']) : null;
            $top = $upper === null ? $taxableMinor : min($taxableMinor, $upper);
            if ($top > $lower) {
                $tax += Money::percentage($top - $lower, (string) ($bracket['rate'] ?? '0'));
            }
            if ($upper === null || $taxableMinor <= $upper) {
                break;
            }
            $lower = $upper;
        }

        return $this->wholeAriary($tax);
    }

    private function share(int $baseMinor, mixed $rate): int
    {
        return $this->wholeAriary(Money::percentage($baseMinor, (string) $rate));
    }

    private function capped(int $grossMinor, mixed $ceiling): int
    {
        return $ceiling !== null && $ceiling !== '' && (float) $ceiling > 0 ? min($grossMinor, Money::toMinor((string) $ceiling)) : $grossMinor;
    }

    private function wholeAriary(int $minor): int
    {
        return intdiv($minor + 50, 100) * 100;
    }

    private function rate(mixed $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',').' %';
    }

    private function ceilingNote(mixed $ceiling): string
    {
        return $ceiling !== null && $ceiling !== '' && (float) $ceiling > 0 ? ' (plafond '.$this->ariary(Money::toMinor((string) $ceiling)).')' : '';
    }

    private function ariary(int $minor): string
    {
        return number_format(intdiv($minor, 100), 0, ',', ' ').' Ar';
    }
}
