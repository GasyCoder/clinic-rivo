<?php

namespace App\Actions\Bonus;

use App\Enums\BonusAwardStatus;
use App\Models\AdvantageAward;
use App\Models\Employee;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\Bonus\AdvantageBoard;
use App\Services\Bonus\AdvantageMeter;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valider l'avantage à l'acte d'une personne pour un mois. Le serveur recompte ; les
 * lignes (article, quantité, prix unitaire, total, passages comptés) et le total sont
 * figés. Un seul avantage en vigueur par personne et par mois ; rien à valider sans acte.
 */
class ValidateAdvantageAwardAction
{
    public function __construct(private readonly AdvantageMeter $meter, private readonly AdvantageBoard $board) {}

    public function execute(Employee|PartnerOrganization $beneficiary, Carbon $month, User $actor): AdvantageAward
    {
        if ($actor->cannot('bonus_awards.validate')) {
            throw new AuthorizationException('Vous ne pouvez pas valider un avantage.');
        }

        $month = $month->copy()->startOfMonth();

        if ($month->isAfter(now()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Un mois à venir n’a pas encore d’avantage.']);
        }

        $isEmployee = $beneficiary instanceof Employee;
        $key = ($isEmployee ? 'E' : 'P').$beneficiary->getKey();

        return DB::transaction(function () use ($beneficiary, $isEmployee, $key, $month, $actor): AdvantageAward {
            $activeKey = AdvantageAward::activeKey($key, $month->format('Y-m'));

            if (AdvantageAward::query()->where('active_key', $activeKey)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['period' => 'Cet avantage est déjà validé pour ce mois.']);
            }

            $articles = $this->board->articles();
            $lines = array_map(function (array $line) {
                unset($line['total_value']);

                return $line;
            }, $this->board->lines($articles, $this->meter->count($articles, $month, $key)[$key] ?? []));
            $total = array_sum(array_map(fn (array $line) => (float) $line['total'], $lines));

            if ($lines === [] || $total <= 0) {
                throw ValidationException::withMessages(['period' => 'Aucun acte compté ce mois-ci pour cette personne : rien à valider.']);
            }

            return AdvantageAward::query()->create([
                'beneficiary_type' => $isEmployee ? 'EMPLOYEE' : 'PARTNER',
                'employee_id' => $isEmployee ? $beneficiary->getKey() : null,
                'partner_organization_id' => $isEmployee ? null : $beneficiary->getKey(),
                'beneficiary_name' => $isEmployee ? Str::squish("{$beneficiary->last_name} {$beneficiary->first_name}") : $beneficiary->name,
                'period' => $month->toDateString(),
                'lines' => $lines,
                'total_amount' => number_format($total, 2, '.', ''),
                'status' => BonusAwardStatus::Validated,
                'active_key' => $activeKey,
                'validated_at' => now(),
                'validated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('validated', $actor),
            ]);
        });
    }

    public function pay(AdvantageAward $award, ?string $note, User $actor): AdvantageAward
    {
        if ($actor->cannot('bonus_awards.pay')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer un avantage versé.');
        }

        return $this->onValidated($award, fn (AdvantageAward $award) => $award->forceFill([
            'status' => BonusAwardStatus::Paid, 'paid_at' => now(), 'paid_by' => $actor->getKey(),
            ...RemoteActorAttribution::fields('paid', $actor), 'payment_note' => filled($note) ? $note : null,
        ]));
    }

    public function cancel(AdvantageAward $award, string $reason, User $actor): AdvantageAward
    {
        if ($actor->cannot('bonus_awards.cancel')) {
            throw new AuthorizationException('Vous ne pouvez pas annuler un avantage.');
        }

        return $this->onValidated($award, fn (AdvantageAward $award) => $award->forceFill([
            'status' => BonusAwardStatus::Cancelled, 'active_key' => null, 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(),
            ...RemoteActorAttribution::fields('cancelled', $actor), 'cancel_reason' => $reason,
        ]));
    }

    private function onValidated(AdvantageAward $award, callable $change): AdvantageAward
    {
        return DB::transaction(function () use ($award, $change): AdvantageAward {
            $award = AdvantageAward::query()->lockForUpdate()->findOrFail($award->getKey());

            if ($award->status !== BonusAwardStatus::Validated) {
                throw ValidationException::withMessages(['award' => $award->status === BonusAwardStatus::Paid
                    ? 'Cet avantage est déjà versé : il ne se modifie plus.'
                    : 'Cet avantage est annulé.']);
            }

            $change($award);
            $award->save();

            return $award;
        });
    }
}
