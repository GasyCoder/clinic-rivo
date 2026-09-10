<?php

namespace App\Http\Controllers;

use App\Actions\Cash\CloseCashSessionAction;
use App\Actions\Cash\OpenCashSessionAction;
use App\Enums\CashSessionStatus;
use App\Enums\InvoiceStatus;
use App\Http\Requests\CloseCashSessionRequest;
use App\Http\Requests\OpenCashSessionRequest;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashController extends Controller
{
    /**
     * A site with configured registers lands on the picker (choose which
     * named caisse to work in); a site with none configured skips straight
     * to the single, unnamed workspace it always had — fully unchanged.
     */
    public function index(Request $request): Response
    {
        $registers = CashRegister::query()->where('active', true)->orderBy('name')->get();

        if ($registers->isEmpty()) {
            // No register configured at all keeps the exact legacy, unnamed
            // site-wide workspace unchanged. But when registers ARE
            // configured and simply all currently deactivated, silently
            // falling back to that workspace would open a session
            // disconnected from any of them — show an explicit "none
            // available" picker state instead.
            if (! CashRegister::query()->exists()) {
                return $this->renderWorkspace($request, null);
            }

            return Inertia::render('Cash/Index', ['registers' => []]);
        }

        // Each register locks its own active_key slot (CashSession::activeKeyFor),
        // so several registers can each hold an open — or locked — session at
        // the same time; none of them blocks any other.
        $openSessions = CashSession::query()
            ->whereIn('cash_register_id', $registers->pluck('id'))
            ->whereNotNull('active_key')
            ->with('opener:id,name')
            ->get()
            ->keyBy('cash_register_id');

        return Inertia::render('Cash/Index', [
            'registers' => $registers->map(function (CashRegister $register) use ($openSessions, $request) {
                $session = $openSessions->get($register->id);

                return [
                    'uuid' => $register->uuid,
                    'name' => $register->name,
                    'is_open' => $session?->status === CashSessionStatus::Open,
                    'is_locked' => $session?->status === CashSessionStatus::Locked,
                    // Only the opener can resume/consult it — a session opened
                    // by someone else is reported so the picker can block it.
                    'is_mine' => $session !== null && $session->opened_by === $request->user()->id,
                    'opener_name' => $session?->opener?->name,
                ];
            }),
        ]);
    }

    public function show(Request $request, CashRegister $cashRegister): Response|RedirectResponse
    {
        $isLocked = CashSession::query()
            ->where('active_key', CashSession::activeKeyFor($cashRegister))
            ->where('status', CashSessionStatus::Locked->value)
            ->exists();

        // Locked means the Super Administration has frozen this till — no
        // one, not even its opener, can transact on it locally until it is
        // unlocked remotely. The workspace has nothing actionable to offer
        // while that holds, so send everyone straight back to the picker
        // instead of rendering a page full of disabled buttons.
        if ($isLocked) {
            return redirect()->route('cash.index')
                ->with('status', "La caisse « {$cashRegister->name} » est verrouillée par la Super Administration. Elle redevient accessible une fois déverrouillée.")
                ->with('status_type', 'warning');
        }

        return $this->renderWorkspace($request, $cashRegister);
    }

    private function renderWorkspace(Request $request, ?CashRegister $cashRegister): Response
    {
        // A register's session is simply its own — no other register's state
        // can ever block it, they each hold an independent active_key slot.
        $session = CashSession::query()
            ->where('active_key', CashSession::activeKeyFor($cashRegister))
            ->with(['opener:id,name', 'locker:id,name', 'register:id,uuid,name'])
            ->first();

        // Only the session's own opener can operate — or even see — it as
        // "theirs"; anyone else gets steered toward a different, available
        // caisse instead of silently touching someone else's till. There is
        // no local escape hatch for a stuck till: only Super Admin's central
        // closure (CatalogActor, bypasses this check entirely) can end its
        // custody without the opener coming back.
        $blockingSession = null;

        if ($session && $session->opened_by !== $request->user()->id) {
            $blockingSession = ['opener_name' => $session->opener?->name];
            $session = null;
        }

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
                'expected_cash' => $session->computeExpectedClosingAmount(),
            ];
        }

        $recentPayments = $request->user()->can('payments.view')
            ? Payment::query()
                ->when($cashRegister, fn ($query) => $query->whereHas(
                    'cashSession',
                    fn ($sessionQuery) => $sessionQuery->where('cash_register_id', $cashRegister->id),
                ))
                ->with([
                    'invoice:id,uuid,patient_id,invoice_number,customer_type,customer_name,customer_phone,source_module',
                    'invoice.patient:id,uuid,patient_number,first_name,last_name',
                    'method:id,name',
                    'receipt:id,uuid,payment_id,receipt_number',
                    'cashier:id,name',
                ])
                ->latest('paid_at')
                ->limit(20)
                ->get()
            : collect();

        $outstandingInvoices = $request->user()->can('billing.view')
            ? Invoice::query()
                ->whereIn('status', [
                    InvoiceStatus::Validated->value,
                    InvoiceStatus::PartiallyPaid->value,
                ])
                ->where('balance_amount', '>', 0)
                ->where(fn ($query) => $query
                    ->whereNull('source_module')
                    ->orWhere('source_module', '!=', 'PHARMACY'))
                ->with([
                    'patient:id,uuid,patient_number,first_name,last_name',
                    'episode:id,uuid,episode_number',
                ])
                ->withCount('lines')
                ->orderByRaw('COALESCE(validated_at, created_at) ASC')
                ->limit(50)
                ->get([
                    'id', 'uuid', 'patient_id', 'episode_id', 'invoice_number',
                    'customer_type', 'customer_name', 'customer_phone', 'source_module',
                    'status', 'total_amount', 'paid_amount', 'balance_amount',
                    'created_at', 'validated_at',
                ])
            : collect();

        $outstandingBalanceMinor = $outstandingInvoices->sum(
            fn (Invoice $invoice): int => Money::toMinor($invoice->balance_amount),
        );

        // A named desk may accept only part of the site's tenders. No
        // configured tender means no restriction, so the filter only applies
        // when the desk actually has a list.
        $acceptedMethodIds = $cashRegister?->acceptedPaymentMethodIds() ?? [];

        $paymentMethods = $request->user()->can('payments.create')
            ? PaymentMethod::query()
                ->where('active', true)
                ->when($acceptedMethodIds !== [], fn ($query) => $query->whereIn('id', $acceptedMethodIds))
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'category', 'affects_cash_balance', 'requires_reference'])
                ->map(fn (PaymentMethod $method) => [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'category' => $method->category->value,
                    'category_label' => $method->category->label(),
                    'category_icon' => $method->category->icon(),
                    'category_position' => $method->category->position(),
                    'affects_cash_balance' => $method->affects_cash_balance,
                    'requires_reference' => $method->requires_reference,
                ])
            : collect();

        $recentSessions = CashSession::query()
            ->when($cashRegister, fn ($query) => $query->where('cash_register_id', $cashRegister->id))
            ->with(['opener:id,name', 'closer:id,name', 'register:id,uuid,name'])
            ->latest('opened_at')
            ->limit(10)
            ->get();

        return Inertia::render('Cash/Show', [
            'cashRegister' => $cashRegister ? ['uuid' => $cashRegister->uuid, 'name' => $cashRegister->name] : null,
            'cashSession' => $session,
            'blockingSession' => $blockingSession,
            'summary' => $summary,
            'outstandingInvoices' => $outstandingInvoices,
            'outstandingSummary' => $request->user()->can('billing.view')
                ? [
                    'count' => $outstandingInvoices->count(),
                    'balance_amount' => Money::fromMinor($outstandingBalanceMinor),
                ]
                : null,
            'paymentMethods' => $paymentMethods,
            'recentPayments' => $recentPayments,
            'recentSessions' => $recentSessions,
            'pharmacyLookup' => $this->pharmacyLookup($request),
        ]);
    }

    /** @return array{reference: string, found: bool, matches: mixed}|null */
    private function pharmacyLookup(Request $request): ?array
    {
        if (! $request->user()->can('billing.view')) {
            return null;
        }

        $reference = trim((string) $request->query('pharmacy_reference', ''));

        if (mb_strlen($reference) > 100) {
            return [
                'reference' => $reference,
                'found' => false,
                'matches' => [],
            ];
        }

        $normalized = mb_strtoupper($reference);
        $matchesQuery = Invoice::query()
            ->where('source_module', 'PHARMACY');

        // A settled ticket is no longer a ticket to control: it became a
        // payment, and lives in the Paiements tab (filterable by Pharmacie).
        // So the idle list only holds what is still to collect. An explicit
        // lookup ignores this: finding a ticket to verify it is already paid
        // is precisely what the QR control is for (ADR-050).
        if ($normalized === '') {
            $matchesQuery->where('balance_amount', '>', 0);
        }

        if ($normalized !== '') {
            $pattern = "%{$normalized}%";
            $matchesQuery->where(function ($query) use ($pattern): void {
                $query->whereRaw('UPPER(invoice_number) LIKE ?', [$pattern])
                    ->orWhereRaw('UPPER(COALESCE(customer_name, ?)) LIKE ?', ['', $pattern])
                    ->orWhereRaw('UPPER(COALESCE(customer_phone, ?)) LIKE ?', ['', $pattern])
                    ->orWhereHas('episode', fn ($episodeQuery) => $episodeQuery
                        ->whereRaw('UPPER(episode_number) LIKE ?', [$pattern]))
                    ->orWhereHas('patient', fn ($patientQuery) => $patientQuery
                        ->whereRaw('UPPER(patient_number) LIKE ?', [$pattern])
                        ->orWhereRaw('UPPER(COALESCE(first_name, ?)) LIKE ?', ['', $pattern])
                        ->orWhereRaw('UPPER(COALESCE(last_name, ?)) LIKE ?', ['', $pattern]));
            });
        }

        $matches = $matchesQuery
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name',
                'episode:id,uuid,episode_number',
            ])
            ->withCount('lines')
            ->when($normalized !== '', fn ($query) => $query
                ->orderByRaw('CASE WHEN UPPER(invoice_number) = ? THEN 0 ELSE 1 END', [$normalized]))
            ->latest('validated_at')
            ->limit(50)
            ->get([
                'id', 'uuid', 'patient_id', 'episode_id', 'invoice_number',
                'customer_type', 'customer_name', 'customer_phone', 'source_module',
                'status', 'total_amount', 'paid_amount', 'balance_amount',
                'created_at', 'validated_at',
            ]);

        return [
            'reference' => $reference,
            'found' => $matches->isNotEmpty(),
            'matches' => $matches,
        ];
    }

    public function open(
        OpenCashSessionRequest $request,
        OpenCashSessionAction $action,
    ): RedirectResponse {
        $session = $action->execute(
            (string) $request->validated('opening_amount'),
            $request->validated('notes'),
            $request->user(),
            $request->validated('cash_register_uuid'),
        );

        $status = "Caisse {$session->session_number} ouverte.";

        // Opening from the register picker's modal never visited the
        // workspace page first, so a plain back() would just re-render the
        // picker instead of landing the cashier on the till they just opened.
        if ($session->cash_register_id !== null) {
            $session->loadMissing('register');

            return redirect()->route('cash.show', $session->register)->with('status', $status);
        }

        return back()->with('status', $status);
    }

    public function close(
        CloseCashSessionRequest $request,
        CloseCashSessionAction $action,
    ): RedirectResponse {
        $cashRegisterUuid = $request->validated('cash_register_uuid');
        $register = $cashRegisterUuid
            ? CashRegister::query()->where('uuid', $cashRegisterUuid)->first()
            : null;

        $session = $action->execute(
            (string) $request->validated('actual_closing_amount'),
            $request->validated('notes'),
            $request->user(),
            register: $register,
        );

        return back()->with('status', "Caisse {$session->session_number} clôturée.");
    }
}
