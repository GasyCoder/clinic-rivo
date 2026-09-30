<?php

namespace App\Actions\StaffDebts;

use App\Enums\SalaryPaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-228 — le RH constate qu'une dette accordée a été remise à l'employé. L'argent
 * part hors RIVO (virement, Mobile Money, espèces), comme la paie (ADR-227) : RIVO
 * garde la date, le moyen et une référence. Les remboursements ne commencent
 * qu'après : la paie ne retient rien sur une dette qui n'a pas été versée.
 */
class DisburseStaffDebtAction
{
    public function __construct(private readonly StaffDebtNotifier $notifier) {}

    /** @param  array{disbursed_on: string, disbursement_mode: string, reference?: ?string, note?: ?string}  $data */
    public function execute(StaffDebt $debt, array $data, User $actor): StaffDebt
    {
        if ($actor->cannot('staff_debts.disburse')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer une dette versée.');
        }

        $mode = SalaryPaymentMode::tryFrom((string) ($data['disbursement_mode'] ?? ''));
        if ($mode === null) {
            throw ValidationException::withMessages(['disbursement_mode' => 'Choisissez comment l’argent a été remis.']);
        }

        $on = Carbon::parse((string) $data['disbursed_on'])->startOfDay();
        if ($on->isAfter(now()->startOfDay())) {
            throw ValidationException::withMessages(['disbursed_on' => 'Un versement se constate une fois fait : la date ne peut pas être à venir.']);
        }

        $debt = DB::transaction(function () use ($debt, $data, $mode, $on, $actor): StaffDebt {
            $debt = StaffDebt::query()->lockForUpdate()->findOrFail($debt->getKey());

            if ($debt->status !== StaffDebtStatus::Approved) {
                throw ValidationException::withMessages(['debt' => $debt->status === StaffDebtStatus::Active
                    ? 'Cette dette est déjà marquée versée.'
                    : 'Seule une dette accordée, pas encore versée, se marque versée.']);
            }

            if ($debt->decided_at !== null && $on->lt($debt->decided_at->copy()->startOfDay())) {
                throw ValidationException::withMessages(['disbursed_on' => 'Le versement ne peut pas précéder l’accord du '.$debt->decided_at->format('d/m/Y').'.']);
            }

            $debt->forceFill([
                'status' => StaffDebtStatus::Active,
                'disbursed_on' => $on->toDateString(),
                'disbursement_mode' => $mode,
                'disbursement_reference' => filled($data['reference'] ?? null) ? Str::squish($data['reference']) : null,
                'disbursement_note' => filled($data['note'] ?? null) ? Str::squish($data['note']) : null,
                'disbursed_at' => now(),
                'disbursed_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('disbursed', $actor),
            ])->save();

            return $debt;
        });

        $plan = StaffDebtLedger::plan($debt->totalDueMinor(), Money::toMinor((string) $debt->installment_amount), $debt->first_period);
        $this->notifier->employee(
            $debt,
            'disbursed',
            'Votre dette '.$debt->number.' vous a été versée',
            StaffDebtNotifier::money($debt->amount).' remis le '.$debt->disbursed_on->format('d/m/Y').' ('.mb_strtolower($mode->label()).'). Premier remboursement : '
                .$debt->first_period->translatedFormat('F Y').($plan ? ', dernier : '.Carbon::createFromFormat('Y-m-d', $plan['last_period'].'-01')->translatedFormat('F Y') : '').'.',
        );

        return $debt;
    }
}
