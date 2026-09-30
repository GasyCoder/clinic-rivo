<?php

namespace App\Services\Bonus;

use App\Enums\BonusAwardStatus;
use App\Models\AdvantageArticle;
use App\Models\AdvantageAward;
use App\Models\Employee;
use App\Models\PartnerOrganization;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Le tableau des avantages à l'acte d'un mois : pour chaque personne, ce que
 * RIVO compte article par article (quantité × prix unitaire = total), et où en
 * est son avantage (à valider, validé, versé, annulé). Le tableau lit ; valider
 * recompte sur le serveur (ValidateAdvantageAwardAction).
 */
class AdvantageBoard
{
    public function __construct(private readonly AdvantageMeter $meter) {}

    /** @return array{people: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function month(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $articles = $this->articles();
        $counted = $this->meter->count($articles, $month);
        $awards = AdvantageAward::query()->whereDate('period', $month->toDateString())
            ->with(['validator:id,name', 'payer:id,name', 'canceller:id,name'])->latest('id')->get();

        $keys = collect(array_keys($counted))->merge($awards->map(fn (AdvantageAward $award) => $award->beneficiaryKey()))->unique()->values();
        $employees = Employee::withTrashed()->with('jobTitle:id,label')
            ->whereKey($keys->filter(fn (string $key) => str_starts_with($key, 'E'))->map(fn (string $key) => (int) substr($key, 1))->all())->get()->keyBy('id');
        $partners = PartnerOrganization::withTrashed()
            ->whereKey($keys->filter(fn (string $key) => str_starts_with($key, 'P'))->map(fn (string $key) => (int) substr($key, 1))->all())->get()->keyBy('id');

        $people = $keys->map(function (string $key) use ($counted, $articles, $awards, $employees, $partners) {
            $person = $this->person($key, $employees, $partners);

            if ($person === null) {
                return null;
            }

            $lines = $this->lines($articles, $counted[$key] ?? []);
            $mine = $awards->filter(fn (AdvantageAward $award) => $award->beneficiaryKey() === $key);

            return [
                ...$person,
                'lines' => $lines,
                'total' => number_format(array_sum(array_column($lines, 'total_value')), 2, '.', ''),
                'award' => $this->award($mine->first(fn (AdvantageAward $award) => $award->status !== BonusAwardStatus::Cancelled)),
                'cancelled' => $mine->where('status', BonusAwardStatus::Cancelled)->values()->map(fn ($award) => $this->award($award))->all(),
            ];
        })->filter()->sortBy('name')->values();

        $active = $awards->where('status', '!==', BonusAwardStatus::Cancelled);

        return [
            'people' => $people->all(),
            'summary' => [
                'to_validate' => $people->filter(fn (array $row) => (float) $row['total'] > 0 && $row['award'] === null)->count(),
                'validated' => $active->where('status', BonusAwardStatus::Validated)->count(),
                'paid' => $active->where('status', BonusAwardStatus::Paid)->count(),
                'amount_to_pay' => number_format((float) $active->where('status', BonusAwardStatus::Validated)->sum('total_amount'), 2, '.', ''),
                'amount_paid' => number_format((float) $active->where('status', BonusAwardStatus::Paid)->sum('total_amount'), 2, '.', ''),
            ],
        ];
    }

    /** Les articles en service, avec leurs actes du catalogue. */
    public function articles(): Collection
    {
        return AdvantageArticle::query()->where('active', true)->with('catalogItems:id')->orderBy('name')->get();
    }

    /**
     * @param  array<int, list<string>>  $counted  id d'article → numéros de passage
     * @return list<array<string, mixed>>
     */
    public function lines(Collection $articles, array $counted): array
    {
        return $articles->filter(fn (AdvantageArticle $article) => ! empty($counted[$article->getKey()]))
            ->map(function (AdvantageArticle $article) use ($counted) {
                $references = $counted[$article->getKey()];
                $total = count($references) * (float) $article->unit_price;

                return [
                    'article' => $article->name,
                    'article_uuid' => $article->uuid,
                    'source' => $article->source->value,
                    'source_label' => $article->source->label(),
                    'quantity' => count($references),
                    'unit_price' => (string) $article->unit_price,
                    'total' => number_format($total, 2, '.', ''),
                    'total_value' => $total,
                    'references' => array_values(array_slice(array_unique($references), 0, 200)),
                ];
            })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function person(string $key, Collection $employees, Collection $partners): ?array
    {
        if (str_starts_with($key, 'E')) {
            $employee = $employees->get((int) substr($key, 1));

            return $employee ? [
                'key' => $key, 'type' => 'EMPLOYEE', 'uuid' => $employee->uuid,
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'detail' => $employee->jobTitle?->label ?? $employee->profession,
                'reference' => $employee->employee_number,
            ] : null;
        }

        $partner = $partners->get((int) substr($key, 1));

        return $partner ? [
            'key' => $key, 'type' => 'PARTNER', 'uuid' => $partner->uuid,
            'name' => $partner->name,
            'detail' => 'Partenaire'.($partner->professionLabel() ? ' · '.$partner->professionLabel() : ''),
            'reference' => null,
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function award(?AdvantageAward $award): ?array
    {
        if (! $award) {
            return null;
        }

        return [
            'uuid' => $award->uuid,
            'status' => $award->status->value,
            'status_label' => $award->status->label(),
            'total_amount' => (string) $award->total_amount,
            'lines' => $award->lines,
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
