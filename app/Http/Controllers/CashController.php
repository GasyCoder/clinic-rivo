<?php

namespace App\Http\Controllers;

use App\Actions\Cash\CloseCashSessionAction;
use App\Actions\Cash\OpenCashSessionAction;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Models\CashSession;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashController extends Controller
{
    public function index(Request $request): Response
    {
        $session = CashSession::query()
            ->where('active_key', 'SINGLE_OPEN_CASH')
            ->with('opener:id,name')
            ->first();

        $summary = null;

        if ($session) {
            $movements = $session->movements()->get(['direction', 'amount', 'affects_cash_balance']);
            $totalMinor = $movements->sum(fn ($movement) => $movement->direction === 'OUT'
                ? -Money::toMinor($movement->amount)
                : Money::toMinor($movement->amount));
            $cashMinor = $movements->where('affects_cash_balance', true)
                ->sum(fn ($movement) => $movement->direction === 'OUT'
                    ? -Money::toMinor($movement->amount)
                    : Money::toMinor($movement->amount));

            $summary = [
                'total_collected' => Money::fromMinor($totalMinor),
                'cash_collected' => Money::fromMinor($cashMinor),
                'expected_cash' => Money::fromMinor(Money::toMinor($session->opening_amount) + $cashMinor),
            ];
        }

        $recentPayments = $request->user()->can('payments.view')
            ? Payment::query()
                ->with([
                    'invoice:id,uuid,patient_id,invoice_number',
                    'invoice.patient:id,uuid,patient_number,first_name,last_name',
                    'method:id,name',
                    'receipt:id,uuid,payment_id,receipt_number',
                    'cashier:id,name',
                ])
                ->latest('paid_at')
                ->limit(20)
                ->get()
            : collect();

        $recentSessions = CashSession::query()
            ->with(['opener:id,name', 'closer:id,name'])
            ->latest('opened_at')
            ->limit(10)
            ->get();

        return Inertia::render('Cash/Index', [
            'cashSession' => $session,
            'summary' => $summary,
            'recentPayments' => $recentPayments,
            'recentSessions' => $recentSessions,
        ]);
    }

    public function open(
        OpenCashSessionRequest $request,
        OpenCashSessionAction $action,
    ): RedirectResponse {
        $session = $action->execute(
            (string) $request->validated('opening_amount'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('status', "Caisse {$session->session_number} ouverte.");
    }

    public function close(
        CloseCashSessionRequest $request,
        CloseCashSessionAction $action,
    ): RedirectResponse {
        $session = $action->execute(
            (string) $request->validated('actual_closing_amount'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('status', "Caisse {$session->session_number} clôturée.");
    }
}
