<?php

namespace App\Services\Maternity;

use App\Models\Pregnancy;
use App\Support\MaternityReference;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Source serveur unique de la DPA et du terme obstétrical. */
final class PregnancyDatingService
{
    public function estimatedDueDate(DateTimeInterface|string $lastMenstrualPeriod): CarbonImmutable
    {
        return CarbonImmutable::parse($lastMenstrualPeriod)
            ->startOfDay()
            ->addDays(MaternityReference::PREGNANCY_TERM_DAYS);
    }

    /**
     * @return array{weeks: int, days: int, total_days: int, label: string}|null
     */
    public function gestationalAge(Pregnancy $pregnancy, DateTimeInterface|string|null $at): ?array
    {
        if ($at === null) {
            return null;
        }

        $anchor = $pregnancy->last_menstrual_period
            ? CarbonImmutable::parse($pregnancy->last_menstrual_period)->startOfDay()
            : ($pregnancy->estimated_due_date
                ? CarbonImmutable::parse($pregnancy->estimated_due_date)->startOfDay()->subDays(MaternityReference::PREGNANCY_TERM_DAYS)
                : null);

        if ($anchor === null) {
            return null;
        }

        $date = CarbonImmutable::parse($at)->startOfDay();
        $totalDays = (int) $anchor->diffInDays($date, false);

        if ($totalDays < 0) {
            return null;
        }

        $weeks = intdiv($totalDays, 7);
        $days = $totalDays % 7;

        return [
            'weeks' => $weeks,
            'days' => $days,
            'total_days' => $totalDays,
            'label' => $this->label($weeks, $days),
        ];
    }

    public function label(?int $weeks, ?int $days): ?string
    {
        if ($weeks === null) {
            return null;
        }

        $days ??= 0;

        return sprintf('%d SA + %d %s', $weeks, $days, $days === 1 ? 'jour' : 'jours');
    }
}
