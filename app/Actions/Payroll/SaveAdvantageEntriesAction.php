<?php

namespace App\Actions\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Enums\SalaryPaymentStatus;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-227 — saisir, corriger, supprimer des avantages de médecins. Tout ou rien : une
 * ligne refusée n'en laisse passer aucune. Refusé pour une personne dont les avantages
 * ne sont pas ouverts, pour un mois dont la paie est déjà marquée payée, et pour un
 * avantage déjà payé — un avantage n'est jamais payé deux fois.
 */
class SaveAdvantageEntriesAction
{
    /**
     * @param  list<array{employee_uuid: string, amount: string|float, reason: string, period: string}>  $lines
     * @return list<AdvantageEntry>
     */
    public function create(array $lines, User $actor): array
    {
        if ($actor->cannot('advantage_entries.create')) {
            throw new AuthorizationException('Vous ne pouvez pas saisir d’avantages.');
        }

        return DB::transaction(function () use ($lines, $actor): array {
            $employees = Employee::query()->with('jobTitle')
                ->whereIn('uuid', array_column($lines, 'employee_uuid'))->get()->keyBy('uuid');
            $created = [];

            foreach ($lines as $index => $line) {
                $employee = $employees->get($line['employee_uuid']);

                if ($employee === null || ! $employee->active || ! $employee->grantsBenefits()) {
                    throw ValidationException::withMessages(["lines.{$index}.employee_uuid" => 'Cette personne n’a pas d’avantages ouverts (étape Rémunération ou module Fonctions).']);
                }

                $period = $this->period($line['period']);
                $this->ensureSalaryOpen($employee, $period, "lines.{$index}.period");

                $created[] = AdvantageEntry::query()->create([
                    'employee_id' => $employee->getKey(),
                    'period' => $period->toDateString(),
                    'amount' => number_format((float) $line['amount'], 2, '.', ''),
                    'reason' => Str::squish($line['reason']),
                    'status' => AdvantageEntryStatus::Pending,
                    'created_by' => $actor->getKey(),
                    ...RemoteActorAttribution::fields('created', $actor),
                ]);
            }

            return $created;
        });
    }

    /** @param  array{amount: string|float, reason: string}  $data */
    public function update(AdvantageEntry $entry, array $data, User $actor): AdvantageEntry
    {
        if ($actor->cannot('advantage_entries.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier un avantage.');
        }

        return DB::transaction(function () use ($entry, $data, $actor): AdvantageEntry {
            $entry = $this->lockPending($entry);
            $entry->forceFill([
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'reason' => Str::squish($data['reason']),
                'updated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('updated', $actor),
            ])->save();

            return $entry;
        });
    }

    public function delete(AdvantageEntry $entry, User $actor): void
    {
        if ($actor->cannot('advantage_entries.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas supprimer un avantage.');
        }

        DB::transaction(function () use ($entry, $actor): void {
            $entry = $this->lockPending($entry);
            $entry->deleted_by = $actor->getKey() ?: null;
            $entry->delete_reason = 'Avantage retiré avant la paie.';
            $entry->delete();
        });
    }

    private function lockPending(AdvantageEntry $entry): AdvantageEntry
    {
        $entry = AdvantageEntry::query()->lockForUpdate()->findOrFail($entry->getKey());

        if ($entry->isPaid()) {
            throw ValidationException::withMessages(['entry' => 'Cet avantage est déjà payé avec la paie du mois : il ne se modifie plus.']);
        }

        $this->ensureSalaryOpen($entry->employee, $entry->period, 'entry');

        return $entry;
    }

    private function ensureSalaryOpen(Employee $employee, Carbon $period, string $field): void
    {
        $paid = SalaryPayment::query()
            ->where('active_key', SalaryPayment::activeKey($employee->getKey(), $period->format('Y-m')))
            ->where('status', SalaryPaymentStatus::Paid)
            ->lockForUpdate()
            ->exists();

        if ($paid) {
            throw ValidationException::withMessages([$field => 'La paie de ce mois est déjà marquée payée pour cette personne : annulez-la d’abord, ou saisissez l’avantage sur le mois suivant.']);
        }
    }

    private function period(string $value): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }
}
