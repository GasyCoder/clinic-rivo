<?php

namespace App\Actions\StaffDebts;

use App\Enums\StaffDebtStatus;
use App\Models\Employee;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Services\StaffDebts\StaffDebtRules;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-245 — le Super Admin crée une dette pour un membre du personnel ; il la valide
 * ensuite par le circuit habituel (DecideStaffDebtAction : accorder, ajuster, refuser).
 *
 * La dette arrive « Demandée », signée du Super Admin. Les limites du site (montant,
 * durée, part du salaire) ne la refusent pas ici : elles se lisent comme dérogations à
 * la validation (ADR-229), où le Super Admin les confirme. Une seule dette en attente
 * de décision par personne, comme pour une demande de l'employé.
 */
class CreateStaffDebtAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly StaffDebtRules $rules,
        private readonly StaffDebtNotifier $notifier,
        private readonly Auditor $auditor,
    ) {}

    /** @param  array{amount: string, reason?: ?string}  $data */
    public function execute(User $actor, Employee $employee, array $data): StaffDebt
    {
        if ($actor->cannot('staff_debts.create')) {
            throw new AuthorizationException('Seul le Super Admin crée une dette du personnel.');
        }

        $amount = Money::toMinor($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
        }

        $reason = filled($data['reason'] ?? null) ? Str::squish((string) $data['reason']) : null;

        $debt = DB::transaction(function () use ($actor, $employee, $data, $amount, $reason): StaffDebt {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $employee->active || $employee->trashed()) {
                throw ValidationException::withMessages(['employee' => 'Cette personne n’est plus en poste : aucune dette ne peut être créée.']);
            }

            $pendingKey = StaffDebt::pendingKey($employee->getKey());
            if (StaffDebt::query()->where('pending_key', $pendingKey)->exists()) {
                throw ValidationException::withMessages(['employee' => 'Une dette de cette personne attend déjà votre décision : validez-la ou refusez-la avant d’en créer une autre.']);
            }

            return StaffDebt::query()->create([
                'number' => $this->numbers->staffDebt(),
                'employee_id' => $employee->getKey(),
                'employee_name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'requested_amount' => Money::normalize($data['amount']),
                'requested_interest_amount' => Money::fromMinor($this->rules->interestMinor($amount)),
                'reason' => $reason,
                'requested_at' => now(),
                'requested_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('requested', $actor),
                'engaged_at_request' => $this->rules->engagedDebts($employee)->count(),
                'status' => StaffDebtStatus::Requested,
                'pending_key' => $pendingKey,
            ]);
        });

        $this->auditor->record('staff_debt.create', $debt, newValues: [
            'number' => $debt->number, 'employee_number' => $debt->employee_number, 'amount' => $debt->requested_amount,
        ]);

        $this->notifier->employee($debt, 'requested', 'Une dette est enregistrée à votre nom',
            StaffDebtNotifier::money($debt->requested_amount).' — '.$debt->number.'. Le remboursement vous sera communiqué à sa validation.');

        return $debt;
    }
}
