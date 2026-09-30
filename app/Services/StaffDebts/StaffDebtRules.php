<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtStatus;
use App\Models\Employee;
use App\Models\StaffDebt;
use App\Models\StaffDebtSetting;
use App\Services\Administration\InternshipDirectory;
use App\Support\Money;
use App\Support\StaffDebts\StaffDebtInterest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-229 — les règles des dettes du personnel sur ce site, écrites une fois : qui peut
 * demander (demandes ouvertes, stagiaires, ancienneté, dettes en cours), ce que des
 * conditions dépassent (montant minimum et maximum, durée, part du salaire), et
 * l'intérêt de la tranche.
 *
 * À la demande de l'employé, une limite dépassée est un refus. À la décision du DG, c'est
 * une dérogation : il la voit, doit la confirmer, et elle reste écrite sur la dette.
 *
 * ADR-229 (amendement du 2026-09-30) — sans montant minimum et maximum réglés, le
 * personnel ne peut pas demander : aucun montant n'est inventé à la place du site.
 */
final class StaffDebtRules
{
    /** Les dettes qui engagent des mensualités : accordées ou en remboursement. */
    public const ENGAGED = [StaffDebtStatus::Approved, StaffDebtStatus::Active];

    public const LIMITS_MISSING = 'Les demandes de dette ne sont pas encore ouvertes sur ce site : le DG doit d’abord régler le montant minimum et le montant maximum.';

    private ?StaffDebtSetting $setting = null;

    private bool $loaded = false;

    public function __construct(private readonly InternshipDirectory $interns) {}

    public function setting(): ?StaffDebtSetting
    {
        if (! $this->loaded) {
            $this->setting = StaffDebtSetting::current();
            $this->loaded = true;
        }

        return $this->setting;
    }

    /** @return list<array{from: string, to: ?string, mode: string, value: string}> */
    public function tiers(): array
    {
        $tiers = $this->setting()?->interest_tiers;

        return is_array($tiers) ? array_values($tiers) : [];
    }

    /** @return array{mode: string, value: string, amount_minor: int, from: string, to: ?string}|null */
    public function interest(int $amountMinor): ?array
    {
        return StaffDebtInterest::for($amountMinor, $this->tiers());
    }

    public function interestMinor(int $amountMinor): int
    {
        return $this->interest($amountMinor)['amount_minor'] ?? 0;
    }

    /**
     * ADR-230 — la règle de pénalité de retard du site, figée sur une dette à son accord ;
     * null quand le site n'en a pas.
     *
     * @return array{penalty_rate: string, penalty_grace_days: int, penalty_cap_rate: ?string}|null
     */
    public function penaltyRule(): ?array
    {
        $setting = $this->setting();
        if ($setting?->penalty_rate === null || (float) $setting->penalty_rate <= 0) {
            return null;
        }

        return [
            'penalty_rate' => (string) $setting->penalty_rate,
            'penalty_grace_days' => (int) ($setting->penalty_grace_days ?? 0),
            'penalty_cap_rate' => $setting->penalty_cap_rate !== null ? (string) $setting->penalty_cap_rate : null,
        ];
    }

    /** Pourquoi cette personne ne peut pas demander maintenant ; null si elle le peut. */
    public function requestBlocker(Employee $employee, ?Carbon $today = null): ?string
    {
        $today ??= now();
        $setting = $this->setting();

        if ($setting !== null && ! $setting->requests_open) {
            return filled($setting->closed_message) ? $setting->closed_message : 'Les demandes de dette sont fermées pour le moment.';
        }

        if (! $this->amountLimitsSet()) {
            return self::LIMITS_MISSING;
        }

        if ($setting->exclude_interns && $this->interns->isIntern($employee)) {
            return 'Les stagiaires ne peuvent pas demander de dette.';
        }

        if ($setting->min_seniority_months) {
            if ($employee->hire_date === null) {
                return 'Votre date d’entrée n’est pas renseignée dans votre fiche : demandez au RH de la compléter avant de faire une demande.';
            }

            $from = $employee->hire_date->copy()->addMonthsNoOverflow($setting->min_seniority_months);
            if ($from->isAfter($today)) {
                return "Il faut {$setting->min_seniority_months} mois d’ancienneté pour demander une dette : vous le pourrez à partir du {$from->format('d/m/Y')}.";
            }
        }

        if ($setting->max_open_debts && $this->engagedDebts($employee)->count() >= $setting->max_open_debts) {
            return 'Vous avez déjà '.$this->plural($setting->max_open_debts, 'dette').' en cours, le maximum sur ce site : attendez d’en avoir soldé une.';
        }

        return null;
    }

    /** Le minimum et le maximum d'une dette sont réglés : la condition pour ouvrir les demandes. */
    public function amountLimitsSet(): bool
    {
        $setting = $this->setting();

        return $setting !== null && $setting->min_amount !== null && $setting->max_amount !== null;
    }

    /**
     * Ce que des conditions dépassent, par champ. Vide quand tout est dans les limites.
     *
     * @return array<string, string>
     */
    public function violations(Employee $employee, int $amountMinor, int $installmentMinor, ?int $ignoreDebtId = null): array
    {
        $setting = $this->setting();
        if ($setting === null || $amountMinor <= 0 || $installmentMinor <= 0) {
            return [];
        }

        $violations = [];
        $total = $amountMinor + $this->interestMinor($amountMinor);

        if ($setting->min_amount !== null && $amountMinor < Money::toMinor((string) $setting->min_amount)) {
            $violations['amount'] = 'Le montant minimum est de '.StaffDebtNotifier::money($setting->min_amount).'.';
        } elseif ($setting->max_amount !== null && $amountMinor > Money::toMinor((string) $setting->max_amount)) {
            $violations['amount'] = 'Le montant maximum est de '.StaffDebtNotifier::money($setting->max_amount).'.';
        }

        if ($setting->max_months) {
            $count = intdiv($total + min($installmentMinor, $total) - 1, min($installmentMinor, $total));
            if ($count > $setting->max_months) {
                // Arrondie à l'ariary supérieur : une mensualité plus petite ne rembourserait pas dans la durée.
                $minimum = intdiv(intdiv($total + $setting->max_months - 1, $setting->max_months) + 99, 100) * 100;
                $violations['installment_amount'] = "Remboursement en {$setting->max_months} mois au plus : il faut une mensualité d’au moins "
                    .StaffDebtNotifier::money(Money::fromMinor($minimum)).' pour '.StaffDebtNotifier::money(Money::fromMinor($total)).' à rembourser.';
            }
        }

        $cap = $this->installmentCapMinor($employee, $ignoreDebtId);
        if ($cap !== null && ! isset($violations['installment_amount']) && $installmentMinor > $cap['available']) {
            $violations['installment_amount'] = "Les mensualités ne peuvent pas dépasser {$setting->max_salary_share} % du salaire déclaré : au plus "
                .StaffDebtNotifier::money(Money::fromMinor($cap['available'])).' par mois'
                .($cap['engaged'] > 0 ? ', '.StaffDebtNotifier::money(Money::fromMinor($cap['engaged'])).' étant déjà pris par d’autres dettes' : '').'.';
        }

        if ($setting->max_open_debts && $this->engagedDebts($employee, $ignoreDebtId)->count() >= $setting->max_open_debts) {
            $violations['debt'] = 'Cette personne a déjà '.$this->plural($this->engagedDebts($employee, $ignoreDebtId)->count(), 'dette').' accordée ou en cours (maximum : '.$setting->max_open_debts.').';
        }

        return $violations;
    }

    /**
     * La mensualité que le salaire permet encore : la part réglée du salaire déclaré, moins
     * les mensualités déjà engagées. Null sans limite ou sans salaire déclaré.
     *
     * @return array{cap: int, engaged: int, available: int}|null
     */
    public function installmentCapMinor(Employee $employee, ?int $ignoreDebtId = null): ?array
    {
        $share = $this->setting()?->max_salary_share;
        if (! $share || ! $employee->remuneration_type?->hasAmount() || (float) $employee->remuneration_amount <= 0) {
            return null;
        }

        $cap = Money::percentage(Money::toMinor((string) $employee->remuneration_amount), (string) $share);
        $engaged = (int) $this->engagedDebts($employee, $ignoreDebtId)
            ->sum(fn (StaffDebt $debt) => Money::toMinor((string) $debt->installment_amount));

        return ['cap' => $cap, 'engaged' => $engaged, 'available' => max(0, $cap - $engaged)];
    }

    /**
     * Les règles telles que les écrans les lisent : l'employé avant de demander, le DG
     * avant de décider, le Super Admin dans les réglages.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $setting = $this->setting();

        return [
            'configured' => $setting !== null,
            'requests_open' => $setting?->requests_open ?? true,
            'amount_limits_set' => $this->amountLimitsSet(),
            // Ce que vit le personnel : ouvertes par le Super Admin ET bornées par un minimum et un maximum.
            'accepting_requests' => ($setting?->requests_open ?? true) && $this->amountLimitsSet(),
            'closed_message' => $setting?->closed_message,
            'min_amount' => $setting?->min_amount !== null ? (string) $setting->min_amount : null,
            'max_amount' => $setting?->max_amount !== null ? (string) $setting->max_amount : null,
            'max_months' => $setting?->max_months,
            'max_salary_share' => $setting?->max_salary_share,
            'max_open_debts' => $setting?->max_open_debts,
            'min_seniority_months' => $setting?->min_seniority_months,
            'exclude_interns' => (bool) ($setting?->exclude_interns ?? false),
            'interest_tiers' => $this->tiers(),
            // ADR-230 — la pénalité de retard, annoncée avant la demande.
            'penalty_rate' => $setting?->penalty_rate !== null ? (string) $setting->penalty_rate : null,
            'penalty_grace_days' => $setting?->penalty_grace_days,
            'penalty_cap_rate' => $setting?->penalty_cap_rate !== null ? (string) $setting->penalty_cap_rate : null,
        ];
    }

    /** @return Collection<int, StaffDebt> */
    public function engagedDebts(Employee $employee, ?int $ignoreDebtId = null)
    {
        return StaffDebt::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', array_map(fn (StaffDebtStatus $status) => $status->value, self::ENGAGED))
            ->when($ignoreDebtId !== null, fn ($query) => $query->whereKeyNot($ignoreDebtId))
            ->get();
    }

    private function plural(int $count, string $word): string
    {
        return $count.' '.$word.($count > 1 ? 's' : '');
    }
}
