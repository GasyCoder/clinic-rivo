<?php

namespace App\Services\Dashboard;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Enums\PaymentStatus;
use App\Models\CareRecord;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacyDispense;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Models\VisitorVisit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Builds the local, permission-scoped operational overview. */
class ClinicOverviewService
{
    /**
     * @return array{
     *     generated_at: string,
     *     metrics: array<int, array<string, int|string|null>>,
     *     trend: array{dates: array<int, string>, series: array<int, array<string, mixed>>}
     * }
     */
    public function for(User $user): array
    {
        $permissions = $user->effectivePermissionNames();
        $startsAt = now()->startOfDay();
        $endsAt = now()->endOfDay();
        $trendStartsAt = $startsAt->copy()->subDays(6);
        $trendDates = collect(range(0, 6))
            ->map(fn (int $offset) => $trendStartsAt->copy()->addDays($offset)->toDateString());
        $metrics = [];
        $trendSeries = [];

        if ($permissions->contains('episodes.view')) {
            $metrics[] = $this->metric(
                key: 'passages_today',
                label: 'Passages aujourd’hui',
                value: Episode::query()->whereBetween('started_at', [$startsAt, $endsAt])->count(),
                description: 'Passages enregistrés sur ce site depuis ce matin.',
                icon: 'activity',
                tone: 'navy',
                href: $permissions->contains('reception.view') ? '/reception' : null,
            );
            $metrics[] = $this->metric(
                key: 'pending_orientation',
                label: 'À orienter',
                value: Episode::query()
                    ->where('status', EpisodeStatus::Open->value)
                    ->where('administrative_status', EpisodeAdministrativeStatus::PendingOrientation->value)
                    ->count(),
                description: 'Passages ouverts encore en attente de routage.',
                icon: 'arrow-right-round',
                tone: 'yellow',
                href: $permissions->contains('reception.view') ? '/reception?filter=pending' : null,
            );
            $trendSeries[] = $this->trendSeries(
                key: 'passages',
                label: 'Passages',
                tone: 'navy',
                query: Episode::query(),
                dateColumn: 'started_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('patients.view')) {
            $metrics[] = $this->metric(
                key: 'patients_today',
                label: 'Nouveaux patients',
                value: Patient::query()->whereBetween('created_at', [$startsAt, $endsAt])->count(),
                description: 'Dossiers permanents créés aujourd’hui.',
                icon: 'users',
                tone: 'cyan',
                href: '/patients',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'patients',
                label: 'Nouveaux patients',
                tone: 'cyan',
                query: Patient::query(),
                dateColumn: 'created_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('consultations.view')) {
            $metrics[] = $this->metric(
                key: 'consultations_today',
                label: 'Consultations aujourd’hui',
                value: Consultation::query()->whereBetween('created_at', [$startsAt, $endsAt])->count(),
                description: 'Consultations enregistrées par la Médecine.',
                icon: 'activity-round',
                tone: 'ocean',
                href: '/medicine',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'consultations',
                label: 'Consultations',
                tone: 'ocean',
                query: Consultation::query(),
                dateColumn: 'created_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('care.view')) {
            $metrics[] = $this->metric(
                key: 'care_records_today',
                label: 'Fiches de soins',
                value: CareRecord::query()->whereBetween('created_at', [$startsAt, $endsAt])->count(),
                description: 'Fiches ouvertes aujourd’hui par les Soins.',
                icon: 'user-check',
                tone: 'green',
                href: '/care',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'care_records',
                label: 'Fiches de soins',
                tone: 'green',
                query: CareRecord::query(),
                dateColumn: 'created_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('surgery.view')) {
            $metrics[] = $this->metric(
                key: 'surgical_requests_today',
                label: 'Demandes chirurgicales',
                value: SurgicalRequest::query()->whereBetween('created_at', [$startsAt, $endsAt])->count(),
                description: 'Demandes créées aujourd’hui pour le Bloc.',
                icon: 'masks',
                tone: 'navy',
                href: '/surgery',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'surgical_requests',
                label: 'Demandes chirurgicales',
                tone: 'yellow',
                query: SurgicalRequest::query(),
                dateColumn: 'created_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('payments.view')) {
            $completedPayments = Payment::query()->where('status', PaymentStatus::Completed->value);
            $metrics[] = $this->metric(
                key: 'payments_today',
                label: 'Encaissements aujourd’hui',
                value: (clone $completedPayments)->whereBetween('paid_at', [$startsAt, $endsAt])->count(),
                description: 'Règlements finalisés aujourd’hui à la Caisse.',
                icon: 'wallet',
                tone: 'green',
                href: $permissions->contains('cash.view') ? '/cash' : null,
            );
            $trendSeries[] = $this->trendSeries(
                key: 'payments',
                label: 'Encaissements',
                tone: 'green',
                query: $completedPayments,
                dateColumn: 'paid_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('pharmacy.view')) {
            $metrics[] = $this->metric(
                key: 'pharmacy_requests_today',
                label: 'Demandes pharmacie',
                value: PharmacyDispense::query()->whereBetween('requested_at', [$startsAt, $endsAt])->count(),
                description: 'Demandes de délivrance reçues aujourd’hui.',
                icon: 'capsule',
                tone: 'ocean',
                href: '/pharmacy',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'pharmacy_requests',
                label: 'Demandes pharmacie',
                tone: 'ocean',
                query: PharmacyDispense::query(),
                dateColumn: 'requested_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('employees.view')) {
            $metrics[] = $this->metric(
                key: 'employees_today',
                label: 'Employés enregistrés',
                value: Employee::query()->whereBetween('created_at', [$startsAt, $endsAt])->count(),
                description: 'Dossiers du personnel ajoutés aujourd’hui.',
                icon: 'briefcase',
                tone: 'navy',
                href: '/administration',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'employees',
                label: 'Employés enregistrés',
                tone: 'navy',
                query: Employee::query(),
                dateColumn: 'created_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        if ($permissions->contains('visitors.view')) {
            $metrics[] = $this->metric(
                key: 'visitors_today',
                label: 'Visiteurs aujourd’hui',
                value: VisitorVisit::query()->whereBetween('checked_in_at', [$startsAt, $endsAt])->count(),
                description: 'Entrées de visiteurs enregistrées aujourd’hui.',
                icon: 'users',
                tone: 'yellow',
                href: '/reception/visitors',
            );
            $trendSeries[] = $this->trendSeries(
                key: 'visitors',
                label: 'Visiteurs',
                tone: 'yellow',
                query: VisitorVisit::query(),
                dateColumn: 'checked_in_at',
                dates: $trendDates,
                startsAt: $trendStartsAt,
                endsAt: $endsAt,
            );
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'trend' => [
                'dates' => $trendDates->values()->all(),
                'series' => $trendSeries,
            ],
        ];
    }

    /** @return array<string, int|string|null> */
    private function metric(
        string $key,
        string $label,
        int $value,
        string $description,
        string $icon,
        string $tone,
        ?string $href,
    ): array {
        return compact('key', 'label', 'value', 'description', 'icon', 'tone', 'href');
    }

    /** @return array{key: string, label: string, tone: string, total: int, values: array<int, int>} */
    private function trendSeries(
        string $key,
        string $label,
        string $tone,
        Builder $query,
        string $dateColumn,
        Collection $dates,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): array {
        $counts = (clone $query)
            ->whereBetween($dateColumn, [$startsAt, $endsAt])
            ->selectRaw("DATE({$dateColumn}) as activity_date, COUNT(*) as aggregate")
            ->groupByRaw("DATE({$dateColumn})")
            ->pluck('aggregate', 'activity_date');

        $values = $dates
            ->map(fn (string $date) => (int) ($counts[$date] ?? 0))
            ->values()
            ->all();

        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'total' => array_sum($values),
            'values' => $values,
        ];
    }
}
