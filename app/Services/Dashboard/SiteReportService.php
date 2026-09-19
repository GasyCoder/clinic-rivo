<?php

namespace App\Services\Dashboard;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\PatientDebt;
use App\Models\Payment;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Le rapport consolidé d'un site, lu par le portail via son API (ADR-102).
 *
 * Le tableau de bord central n'avait aucune donnée : il affichait « — »
 * partout, faute d'un endpoint qui compte quoi que ce soit. Ce service est
 * cet endpoint, exécuté **dans** la base du site — `admin.rivo.mg` n'ouvre
 * jamais une connexion SQL vers une clinique (ADR-004, ADR-027).
 *
 * Deux règles le gouvernent.
 *
 * **Une section absente n'est pas une section vide.** Sans la permission, ou
 * sans la table, la section revient `available: false` avec son motif, et
 * l'écran écrit « indisponible ». Afficher `0` ferait lire « aucune recette
 * aujourd'hui » là où il faut lire « je n'ai pas le droit de compter » —
 * c'est précisément ce que l'ADR-048 refuse pour le rapport Chirurgie.
 *
 * **Aucun chiffre n'est inventé.** Chaque valeur sort d'une colonne
 * réellement écrite par un circuit existant : les encaissements de
 * `payments`, le reste dû de `invoices.balance_amount` (ADR-090), les
 * créances de `patient_debts`. Rien n'est extrapolé, ni projeté.
 */
class SiteReportService
{
    /** Bornes de la fenêtre d'analyse : au-delà, la série devient illisible. */
    public const MIN_DAYS = 7;

    public const MAX_DAYS = 90;

    public const DEFAULT_DAYS = 30;

    public function __construct(private readonly ClinicOverviewService $overview) {}

    /** @return array<string, mixed> */
    public function overview(User|CatalogActor $actor, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
        $endsAt = CarbonImmutable::now()->endOfDay();
        $startsAt = $endsAt->subDays($days - 1)->startOfDay();
        $dates = collect(range(0, $days - 1))
            ->map(fn (int $offset) => $startsAt->addDays($offset)->toDateString());

        return [
            'generated_at' => now()->toIso8601String(),
            'range' => [
                'days' => $days,
                'from' => $startsAt->toDateString(),
                'to' => $endsAt->toDateString(),
            ],
            'sections' => [
                'activity' => $this->activity($actor, $dates, $startsAt, $endsAt),
                'finance' => $this->finance($actor, $dates, $startsAt, $endsAt),
                'clinical' => $this->clinical($actor),
                'pharmacy' => $this->pharmacy($actor),
                'people' => $this->people($actor),
            ],
        ];
    }

    /**
     * Le mouvement du site : passages, urgences, nouveaux patients.
     *
     * @return array<string, mixed>
     */
    private function activity(User|CatalogActor $actor, Collection $dates, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        if ($actor->cannot('episodes.view')) {
            return $this->unavailable('Il manque la permission « Voir les passages ».');
        }

        $today = [CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay()];

        return [
            'available' => true,
            'today' => [
                'episodes' => Episode::query()->whereBetween('started_at', $today)->count(),
                'emergencies' => Episode::query()->whereBetween('started_at', $today)->where('priority', 'EMERGENCY')->count(),
                'new_patients' => Patient::query()->whereBetween('created_at', $today)->count(),
            ],
            'open_episodes' => Episode::query()->where('status', EpisodeStatus::Open->value)->count(),
            'total_patients' => Patient::query()->count(),
            'trend' => [
                'dates' => $dates->all(),
                'series' => [
                    $this->series('episodes', 'Passages', 'navy', Episode::query(), 'started_at', $dates, $startsAt, $endsAt),
                    $this->series('emergencies', 'Urgences', 'yellow', Episode::query()->where('priority', 'EMERGENCY'), 'started_at', $dates, $startsAt, $endsAt),
                    $this->series('patients', 'Nouveaux patients', 'green', Patient::query(), 'created_at', $dates, $startsAt, $endsAt),
                ],
            ],
            'demographics' => $actor->can('patients.view') ? $this->overview->patientDemographics() : null,
        ];
    }

    /**
     * L'argent réellement enregistré : facturé, encaissé, restant dû.
     *
     * Une facture annulée n'entre dans aucun total (ADR-090) et un paiement
     * annulé n'est pas un encaissement — les deux seraient comptés par un
     * `sum()` naïf.
     *
     * @return array<string, mixed>
     */
    private function finance(User|CatalogActor $actor, Collection $dates, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        if ($actor->cannot('billing.view')) {
            return $this->unavailable('Il manque la permission « Voir la facturation ».');
        }

        $invoices = Invoice::query()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->whereBetween('created_at', [$startsAt, $endsAt]);

        $paid = Payment::query()
            ->where('status', PaymentStatus::Completed->value)
            ->whereBetween('paid_at', [$startsAt, $endsAt]);

        $canSeePayments = $actor->can('payments.view');

        return [
            'available' => true,
            'totals' => [
                'invoiced' => (float) (clone $invoices)->sum('total_amount'),
                'collected' => $canSeePayments ? (float) (clone $paid)->sum('amount') : null,
                // Le reste dû se lit sur les factures non annulées, jamais
                // sur la différence facturé − encaissé : une prise en charge
                // mutuelle à 100 % solde une facture sans aucun paiement.
                'outstanding' => (float) Invoice::query()
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)
                    ->sum('balance_amount'),
                'invoices' => (clone $invoices)->count(),
            ],
            'debts' => $actor->can('debts.view')
                ? [
                    'count' => PatientDebt::query()->count(),
                    'amount' => (float) PatientDebt::query()->sum('amount'),
                ]
                : null,
            'by_method' => $canSeePayments
                ? (clone $paid)
                    ->selectRaw('payment_method_id, COUNT(*) as operations, SUM(amount) as amount')
                    ->groupBy('payment_method_id')
                    ->with('method:id,name')
                    ->get()
                    ->map(fn (Payment $row) => [
                        'label' => $row->method?->name ?? 'Mode inconnu',
                        'operations' => (int) $row->operations,
                        'amount' => (float) $row->amount,
                    ])
                    ->sortByDesc('amount')
                    ->values()
                    ->all()
                : null,
            'trend' => $canSeePayments
                ? [
                    'dates' => $dates->all(),
                    'series' => [$this->sumSeries('collected', 'Encaissements', 'green', $paid, 'paid_at', 'amount', $dates)],
                ]
                : null,
        ];
    }

    /**
     * Où va le travail : la file de chaque service, telle qu'elle est.
     *
     * Une orientation annulée est exclue — elle a quitté la file et ne
     * représente plus de travail à faire (ADR-079).
     *
     * @return array<string, mixed>
     */
    private function clinical(User|CatalogActor $actor): array
    {
        if ($actor->cannot('episodes.view')) {
            return $this->unavailable('Il manque la permission « Voir les passages ».');
        }

        // Le modèle transforme `status` et `destination_module` en enums :
        // regrouper sur ces colonnes donnerait des clés qui ne sont pas des
        // chaînes. On repasse en valeurs brutes avant d'agréger.
        $rows = EpisodeOrientation::query()
            ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
            ->selectRaw('destination_module, status, COUNT(*) as aggregate')
            ->groupBy('destination_module', 'status')
            ->get()
            ->map(fn (EpisodeOrientation $row) => [
                'module' => $this->rawValue($row->getAttributes()['destination_module'] ?? null),
                'status' => $this->rawValue($row->getAttributes()['status'] ?? null),
                'aggregate' => (int) $row->aggregate,
            ]);

        $destinations = $rows
            ->groupBy('module')
            ->map(function (Collection $group, string $module) {
                $byStatus = $group->pluck('aggregate', 'status');
                $pending = (int) $byStatus->get(EpisodeOrientationStatus::Pending->value, 0);
                $inProgress = (int) $byStatus->get(EpisodeOrientationStatus::InProgress->value, 0);

                return [
                    'key' => $module,
                    'label' => $this->moduleLabel($module),
                    'pending' => $pending,
                    'in_progress' => $inProgress,
                    'completed' => (int) $byStatus->get(EpisodeOrientationStatus::Completed->value, 0),
                    'waiting' => $pending + $inProgress,
                ];
            })
            ->sortByDesc('waiting')
            ->values()
            ->all();

        return ['available' => true, 'destinations' => $destinations];
    }

    /** @return array<string, mixed> */
    private function pharmacy(User|CatalogActor $actor): array
    {
        if ($actor->cannot('stock.view')) {
            return $this->unavailable('Il manque la permission « Voir le stock ».');
        }

        $soon = CarbonImmutable::now()->addDays(90)->toDateString();
        $today = CarbonImmutable::now()->toDateString();

        return [
            'available' => true,
            'medicines' => Medicine::query()->count(),
            'lots' => MedicineLot::query()->count(),
            'expiring_soon' => MedicineLot::query()
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [$today, $soon])
                ->where('quantity_on_hand', '>', 0)
                ->count(),
            'expired' => MedicineLot::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $today)
                ->where('quantity_on_hand', '>', 0)
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function people(User|CatalogActor $actor): array
    {
        if ($actor->cannot('employees.view')) {
            return $this->unavailable('Il manque la permission « Voir les employés ».');
        }

        return [
            'available' => true,
            'employees' => Employee::query()->count(),
            'pending_leaves' => LeaveRequest::query()->where('status', 'PENDING')->count(),
            'accounts' => User::query()->where('active', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason];
    }

    /** @return array<string, mixed> */
    private function series(string $key, string $label, string $tone, Builder $query, string $column, Collection $dates, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $counts = $query
            ->whereBetween($column, [$startsAt, $endsAt])
            ->selectRaw("DATE({$column}) as activity_date, COUNT(*) as aggregate")
            ->groupByRaw("DATE({$column})")
            ->pluck('aggregate', 'activity_date');

        return $this->plot($key, $label, $tone, $dates, $counts);
    }

    /** @return array<string, mixed> */
    private function sumSeries(string $key, string $label, string $tone, Builder $query, string $column, string $amountColumn, Collection $dates): array
    {
        $totals = (clone $query)
            ->selectRaw("DATE({$column}) as activity_date, SUM({$amountColumn}) as aggregate")
            ->groupByRaw("DATE({$column})")
            ->pluck('aggregate', 'activity_date');

        return $this->plot($key, $label, $tone, $dates, $totals);
    }

    /** @return array<string, mixed> */
    private function plot(string $key, string $label, string $tone, Collection $dates, Collection $byDate): array
    {
        $values = $dates->map(fn (string $date) => (float) ($byDate[$date] ?? 0))->values()->all();

        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'total' => array_sum($values),
            'values' => $values,
        ];
    }

    /** La valeur brute d'une colonne, qu'un cast enum l'ait déjà convertie ou non. */
    private function rawValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function moduleLabel(string $module): string
    {
        return CatalogModule::tryFrom($module)?->label() ?? $module;
    }
}
