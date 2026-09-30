<?php

namespace App\Services\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Models\AdvantageArticle;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ADR-227 — les avantages saisis d'un mois, par médecin, et la liste des personnes à qui
 * l'on peut en saisir : en poste, avantages ouverts (case « Avantages », sinon la fonction
 * — « Médecin » d'office, ADR-221/226). Rien n'est saisi pour un nom libre.
 */
class AdvantageEntryDirectory
{
    /** Les personnes à qui l'on peut saisir un avantage aujourd'hui. */
    public function eligible(): Collection
    {
        return Employee::query()
            ->with('jobTitle')
            ->where('active', true)
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->filter(fn (Employee $employee) => $employee->grantsBenefits())
            ->values();
    }

    /** @return array{doctors: list<array<string, mixed>>, people: list<array<string, mixed>>, summary: array<string, mixed>, reasons: list<string>} */
    public function month(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $entries = AdvantageEntry::query()
            ->whereDate('period', $month->toDateString())
            ->with(['employee.jobTitle', 'creator:id,name'])
            ->orderBy('id')
            ->get();

        $people = $entries->groupBy('employee_id')->map(function (Collection $mine) {
            $employee = $mine->first()->employee;
            $pending = $mine->where('status', AdvantageEntryStatus::Pending);
            $paid = $mine->where('status', AdvantageEntryStatus::Paid);

            return [
                ...$this->person($employee),
                'count' => $mine->count(),
                'total' => $this->money($mine->sum('amount')),
                'pending_total' => $this->money($pending->sum('amount')),
                'paid_total' => $this->money($paid->sum('amount')),
                'entries' => $mine->map(fn (AdvantageEntry $entry) => [
                    'uuid' => $entry->uuid,
                    'amount' => (string) $entry->amount,
                    'reason' => $entry->reason,
                    'status' => $entry->status->value,
                    'status_label' => $entry->status->label(),
                    'editable' => ! $entry->isPaid(),
                    'created_by' => RemoteActorAttribution::name($entry->creator?->name, $entry->external_created_by_name),
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->sortBy('name')->values();

        return [
            'doctors' => $this->eligible()->map(fn (Employee $employee) => $this->person($employee))->all(),
            'people' => $people->all(),
            'summary' => [
                'people' => $people->count(),
                'count' => $entries->count(),
                'total' => $this->money($entries->sum('amount')),
                'pending_total' => $this->money($entries->where('status', AdvantageEntryStatus::Pending)->sum('amount')),
                'paid_total' => $this->money($entries->where('status', AdvantageEntryStatus::Paid)->sum('amount')),
            ],
            'reasons' => $this->reasons(),
        ];
    }

    /** Motifs proposés : les articles d'avantage (ECHO, ECG…) puis les motifs déjà saisis. */
    public function reasons(): array
    {
        return AdvantageArticle::query()->where('active', true)->orderBy('name')->pluck('name')
            ->merge(AdvantageEntry::query()->select('reason')->distinct()->orderBy('reason')->limit(200)->pluck('reason'))
            ->map(fn ($reason) => Str::squish((string) $reason))
            ->filter()
            ->unique(fn (string $reason) => Str::lower(Str::ascii($reason)))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function person(Employee $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
            'employee_number' => $employee->employee_number,
            'job_title' => $employee->jobTitle?->label ?? $employee->profession,
            'in_post' => $employee->active && ! $employee->trashed(),
        ];
    }

    private function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
