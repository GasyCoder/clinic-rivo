<?php

namespace App\Services\Bonus;

use App\Enums\BonusAwardStatus;
use App\Models\BonusAward;
use App\Models\BonusCategory;
use App\Models\Employee;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ADR-212 — le tableau des bonus d'un mois : pour chaque catégorie, chaque
 * membre du personnel concerné, ce qu'il compte, s'il atteint le seuil, et où
 * en est son bonus (à valider, validé, versé, annulé).
 *
 * Le tableau ne décide rien : il lit. Valider recompte sur le serveur
 * (ValidateBonusAwardAction). Une catégorie archivée ne paraît que si elle a
 * déjà donné un bonus ce mois-là : il reste à verser.
 */
class BonusBoard
{
    public function __construct(private readonly BonusMeter $meter) {}

    /** @return array{categories: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function month(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $awards = BonusAward::query()
            ->whereDate('period', $month->toDateString())
            ->with([
                'validator:id,name', 'payer:id,name', 'canceller:id,name',
                'employee' => fn ($query) => $query->withTrashed()->with('jobTitle:id,label'),
            ])
            ->latest('id')
            ->get();

        $categories = BonusCategory::withTrashed()
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereIn('id', $awards->pluck('bonus_category_id')->unique()))
            ->with(['employees' => fn ($query) => $query->withTrashed()->with('jobTitle:id,label')->orderBy('last_name')->orderBy('first_name')])
            ->orderBy('name')
            ->get();

        $rows = $categories->map(function (BonusCategory $category) use ($month, $awards) {
            // Un bonus déjà donné reste visible même si la personne a quitté la
            // catégorie depuis : il peut rester à verser.
            $members = $category->employees->keyBy('id');
            $awarded = $awards->where('bonus_category_id', $category->getKey())
                ->map(fn (BonusAward $award) => $award->employee)
                ->filter()
                ->reject(fn (Employee $employee) => $members->has($employee->getKey()))
                ->unique('id');
            $employees = $category->employees->concat($awarded)->values();
            $counted = $this->meter->patients($category->measure, $employees, $month);

            return [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'measure' => $category->measure->value,
                'measure_label' => $category->measure->label(),
                'measure_description' => $category->measure->description(),
                'threshold' => $category->threshold,
                'amount' => (string) $category->amount,
                'archived' => $category->trashed(),
                'employees' => $employees->map(function (Employee $employee) use ($category, $counted, $awards, $members) {
                    $patients = $counted[$employee->getKey()] ?? [];
                    $categoryAwards = $awards->where('bonus_category_id', $category->getKey())->where('employee_id', $employee->getKey());

                    return [
                        'uuid' => $employee->uuid,
                        'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                        'employee_number' => $employee->employee_number,
                        'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                        'in_post' => $employee->active && ! $employee->trashed(),
                        'in_category' => $members->has($employee->getKey()),
                        // Les patients soignés passent par le compte de connexion (ADR-188).
                        'has_account' => $employee->user_id !== null,
                        'count' => count($patients),
                        'reached' => count($patients) >= $category->threshold,
                        'patients' => array_slice(array_column($patients, 'patient_number'), 0, 60),
                        'award' => $this->award($categoryAwards->first(fn (BonusAward $award) => $award->status !== BonusAwardStatus::Cancelled)),
                        'cancelled' => $categoryAwards->where('status', BonusAwardStatus::Cancelled)->values()->map(fn (BonusAward $award) => $this->award($award))->all(),
                    ];
                })->values()->all(),
            ];
        })->values();

        $employees = $rows->flatMap(fn (array $category) => $category['employees']);
        $active = $awards->where('status', '!==', BonusAwardStatus::Cancelled);

        return [
            'categories' => $rows->all(),
            'summary' => [
                'to_validate' => $employees->filter(fn (array $row) => $row['reached'] && $row['award'] === null)->count(),
                'validated' => $active->where('status', BonusAwardStatus::Validated)->count(),
                'paid' => $active->where('status', BonusAwardStatus::Paid)->count(),
                'amount_to_pay' => number_format((float) $active->where('status', BonusAwardStatus::Validated)->sum('amount'), 2, '.', ''),
                'amount_paid' => number_format((float) $active->where('status', BonusAwardStatus::Paid)->sum('amount'), 2, '.', ''),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function award(?BonusAward $award): ?array
    {
        if (! $award) {
            return null;
        }

        return [
            'uuid' => $award->uuid,
            'status' => $award->status->value,
            'status_label' => $award->status->label(),
            'patients_count' => $award->patients_count,
            'threshold' => $award->threshold,
            'amount' => (string) $award->amount,
            'counted_patients' => $award->counted_patients ?? [],
            'validated_at' => $award->validated_at?->toIso8601String(),
            'validated_by' => RemoteActorAttribution::name($award->validator?->name, $award->external_validated_by_name),
            'paid_at' => $award->paid_at?->toIso8601String(),
            'paid_by' => RemoteActorAttribution::name($award->payer?->name, $award->external_paid_by_name),
            'payment_note' => $award->payment_note,
            'cancelled_at' => $award->cancelled_at?->toIso8601String(),
            'cancelled_by' => RemoteActorAttribution::name($award->canceller?->name, $award->external_cancelled_by_name),
            'cancel_reason' => $award->cancel_reason,
        ];
    }
}
