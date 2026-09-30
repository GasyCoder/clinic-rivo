<?php

namespace App\Actions\StaffDebts;

use App\Enums\StaffDebtStatus;
use App\Models\Employee;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use App\Support\StaffDebts\StaffDebtTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-228 — un membre du personnel demande une dette depuis son compte : le montant, la
 * mensualité qu'il peut rembourser, le mois où il commence, et le motif. La demande est
 * la sienne : son compte doit être relié à sa fiche (ADR-188), et il doit être en poste.
 * Une seule demande en attente à la fois. Il peut la retirer tant que le DG n'a pas décidé.
 */
class RequestStaffDebtAction
{
    public function __construct(private readonly FinancialNumberGenerator $numbers) {}

    /** @param  array{amount: string, installment_amount: string, first_period: string, reason: string}  $data */
    public function execute(User $actor, array $data): StaffDebt
    {
        if ($actor->cannot('staff_debts.request')) {
            throw new AuthorizationException('Vous ne pouvez pas demander de dette.');
        }

        $employee = self::employeeOf($actor);
        if ($employee === null) {
            throw ValidationException::withMessages(['employee' => 'Votre compte n’est relié à aucune fiche du personnel : demandez au RH de le relier avant de faire une demande.']);
        }

        if (! $employee->active || $employee->trashed()) {
            throw ValidationException::withMessages(['employee' => 'Votre fiche n’est plus en poste : aucune demande n’est possible.']);
        }

        $first = StaffDebtTerms::period($data['first_period'], 'first_period');
        StaffDebtTerms::assert(Money::toMinor($data['amount']), Money::toMinor($data['installment_amount']), $first, 'amount', 'installment_amount', 'first_period');

        return DB::transaction(function () use ($actor, $employee, $data, $first): StaffDebt {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $pendingKey = StaffDebt::pendingKey($employee->getKey());
            if (StaffDebt::query()->where('pending_key', $pendingKey)->exists()) {
                throw ValidationException::withMessages(['employee' => 'Vous avez déjà une demande en attente : attendez la décision, ou retirez-la avant d’en faire une autre.']);
            }

            return StaffDebt::query()->create([
                'number' => $this->numbers->staffDebt(),
                'employee_id' => $employee->getKey(),
                'employee_name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'requested_amount' => Money::normalize($data['amount']),
                'requested_installment' => Money::normalize($data['installment_amount']),
                'requested_first_period' => $first->toDateString(),
                'reason' => Str::squish($data['reason']),
                'requested_at' => now(),
                'requested_by' => $actor->getKey(),
                'status' => StaffDebtStatus::Requested,
                'pending_key' => $pendingKey,
            ]);
        });
    }

    /** L'employé retire sa propre demande, tant que le DG n'a pas décidé. */
    public function withdraw(StaffDebt $debt, User $actor): StaffDebt
    {
        return DB::transaction(function () use ($debt, $actor): StaffDebt {
            $debt = StaffDebt::query()->lockForUpdate()->findOrFail($debt->getKey());
            $employee = self::employeeOf($actor);

            if ($employee === null || $debt->employee_id !== $employee->getKey()) {
                throw new AuthorizationException('Cette demande n’est pas la vôtre.');
            }

            if ($debt->status !== StaffDebtStatus::Requested) {
                throw ValidationException::withMessages(['debt' => 'Le DG a déjà décidé : la demande ne se retire plus.']);
            }

            $debt->forceFill([
                'status' => StaffDebtStatus::Cancelled, 'pending_key' => null,
                'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(),
                'external_cancelled_by_uuid' => null, 'external_cancelled_by_name' => null,
                'cancel_reason' => 'Demande retirée par l’employé.',
            ])->save();

            return $debt;
        });
    }

    /** La fiche du personnel reliée à ce compte (ADR-188), archivée comprise. */
    public static function employeeOf(User $user): ?Employee
    {
        if ($user->getKey() === null) {
            return null;
        }

        return Employee::withTrashed()->where('user_id', $user->getKey())->first();
    }

    /** Le mois courant, premier mois possible d'un remboursement. */
    public static function currentMonth(): Carbon
    {
        return now()->startOfMonth();
    }
}
