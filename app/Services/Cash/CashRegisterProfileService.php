<?php

namespace App\Services\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CashRegisterProfileService
{
    /** @return array<string, mixed> */
    public function build(CashRegister $register): array
    {
        $sessions = $register->sessions()
            ->with([
                'opener:id,name', 'closer:id,name', 'locker:id,name', 'unlocker:id,name',
            ])
            ->withCount(['payments', 'movements'])
            ->latest('opened_at')
            ->limit(12)
            ->get();

        $activeSession = $sessions->first(fn (CashSession $session) => $session->active_key === CashSession::activeKeyFor($register));
        $focusSession = $activeSession ?? $sessions->first();
        $focusMovements = $focusSession
            ? $focusSession->movements()
                ->with(['method:id,name', 'recorder:id,name', 'payment:id,payment_number'])
                ->latest('occurred_at')
                ->limit(50)
                ->get()
            : collect();
        $allMovements = CashMovement::query()
            ->whereHas('cashSession', fn ($query) => $query->where('cash_register_id', $register->getKey()))
            ->with('method:id,name')
            ->get(['id', 'payment_method_id', 'direction', 'amount', 'affects_cash_balance', 'occurred_at']);

        $register->loadCount('sessions')->loadMissing('assignedUser:id,uuid,name,active,deactivated_at');

        return [
            'register' => [
                'uuid' => $register->uuid,
                'name' => $register->name,
                'color' => $register->color,
                'opening_fund_amount' => $register->opening_fund_amount,
                'assigned_user' => $register->assignedUser ? [
                    'uuid' => $register->assignedUser->uuid,
                    'name' => $register->assignedUser->name,
                    'active' => $register->assignedUser->isActive(),
                ] : null,
                'active' => (bool) $register->active,
                'archived' => $register->trashed(),
                'sessions_count' => (int) $register->sessions_count,
                'updated_at' => $register->updated_at?->toIso8601String(),
            ],
            'active_session' => $activeSession ? $this->serializeSession($activeSession, true) : null,
            'focus_session' => $focusSession ? $this->serializeSession($focusSession, true) : null,
            'movements' => $focusMovements->map(fn (CashMovement $movement) => [
                'uuid' => $movement->uuid,
                'type' => $movement->type,
                'direction' => $movement->direction,
                'amount' => $movement->amount,
                'affects_cash_balance' => (bool) $movement->affects_cash_balance,
                'description' => $movement->description,
                'payment_method' => $movement->method?->name,
                'payment_number' => $movement->payment?->payment_number,
                'recorded_by' => $movement->recorder?->name,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ])->values(),
            'recent_sessions' => $sessions->map(fn (CashSession $session) => $this->serializeSession($session))->values(),
            'lifetime' => [
                'sessions_count' => (int) $register->sessions_count,
                'closed_sessions_count' => $register->sessions()
                    ->where('status', CashSessionStatus::Closed->value)
                    ->count(),
                ...$this->movementTotals($allMovements),
            ],
            'analytics' => $this->analytics($allMovements),
        ];
    }

    /** @param Collection<int, CashMovement> $movements
     * @return array<string, mixed>
     */
    private function analytics(Collection $movements): array
    {
        $start = CarbonImmutable::today()->subDays(29);
        $dates = collect(range(0, 29))->map(fn (int $offset) => $start->addDays($offset)->toDateString());
        $recent = $movements->filter(fn (CashMovement $movement) => $movement->occurred_at?->greaterThanOrEqualTo($start));

        $amountFor = function (string $date, string $direction) use ($recent): float {
            $minor = $recent
                ->filter(fn (CashMovement $movement) => $movement->direction === $direction
                    && $movement->occurred_at?->toDateString() === $date)
                ->sum(fn (CashMovement $movement) => Money::toMinor($movement->amount));

            return $minor / 100;
        };

        $incoming = $dates->map(fn (string $date) => $amountFor($date, 'IN'))->all();
        $outgoing = $dates->map(fn (string $date) => $amountFor($date, 'OUT'))->all();

        $byMethod = $recent
            ->where('direction', 'IN')
            ->groupBy(fn (CashMovement $movement) => $movement->payment_method_id ?: 'other')
            ->map(function (Collection $rows, int|string $key): array {
                $first = $rows->first();

                return [
                    'key' => (string) $key,
                    'label' => $first?->method?->name ?? 'Autre mouvement',
                    'value' => $rows->sum(fn (CashMovement $movement) => Money::toMinor($movement->amount)) / 100,
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'period_days' => 30,
            'trend' => [
                'dates' => $dates->all(),
                'series' => [
                    ['key' => 'incoming', 'label' => 'Entrées', 'tone' => 'green', 'values' => $incoming, 'total' => array_sum($incoming)],
                    ['key' => 'outgoing', 'label' => 'Sorties / annulations', 'tone' => 'yellow', 'values' => $outgoing, 'total' => array_sum($outgoing)],
                ],
            ],
            'payment_methods' => $byMethod,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSession(CashSession $session, bool $withTotals = false): array
    {
        $status = $session->status instanceof CashSessionStatus
            ? $session->status->value
            : (string) $session->status;
        $data = [
            'uuid' => $session->uuid,
            'session_number' => $session->session_number,
            'status' => $status,
            'opening_amount' => $session->opening_amount,
            'expected_closing_amount' => $session->expected_closing_amount,
            'actual_closing_amount' => $session->actual_closing_amount,
            'variance_amount' => $session->variance_amount,
            'opened_by' => $session->opener?->name,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_by' => $session->closer?->name ?? $session->external_closed_by_name,
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closing_reason' => $session->closing_reason,
            'locked_by' => $session->locker?->name ?? $session->external_locked_by_name,
            'locked_at' => $session->locked_at?->toIso8601String(),
            'lock_reason' => $session->lock_reason,
            'unlocked_by' => $session->unlocker?->name ?? $session->external_unlocked_by_name,
            'unlocked_at' => $session->unlocked_at?->toIso8601String(),
            'payments_count' => (int) ($session->payments_count ?? $session->payments()->count()),
            'movements_count' => (int) ($session->movements_count ?? $session->movements()->count()),
            'notes' => $session->notes,
        ];

        if ($withTotals) {
            $movements = $session->movements()->get(['direction', 'amount', 'affects_cash_balance']);
            $data['totals'] = [
                ...$this->movementTotals($movements),
                'expected_cash' => $session->status === CashSessionStatus::Closed
                    ? $session->expected_closing_amount
                    : $session->computeExpectedClosingAmount(),
            ];
        }

        return $data;
    }

    /** @param Collection<int, CashMovement> $movements
     * @return array{total_in: string, total_out: string, net_total: string, cash_net: string}
     */
    private function movementTotals(Collection $movements): array
    {
        $incoming = $movements->where('direction', 'IN')->sum(fn (CashMovement $movement) => Money::toMinor($movement->amount));
        $outgoing = $movements->where('direction', 'OUT')->sum(fn (CashMovement $movement) => Money::toMinor($movement->amount));
        $cashNet = $movements->where('affects_cash_balance', true)->sum(
            fn (CashMovement $movement) => $movement->direction === 'OUT'
                ? -Money::toMinor($movement->amount)
                : Money::toMinor($movement->amount),
        );

        return [
            'total_in' => Money::fromMinor($incoming),
            'total_out' => Money::fromMinor($outgoing),
            'net_total' => Money::fromMinor($incoming - $outgoing),
            'cash_net' => Money::fromMinor($cashNet),
        ];
    }
}
