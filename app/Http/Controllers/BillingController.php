<?php

namespace App\Http\Controllers;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Enums\CashSessionStatus;
use App\Enums\InvoiceStatus;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load([
            'patient:id,uuid,patient_number,first_name,last_name',
            'episode:id,uuid,episode_number',
            'lines.billableItem:id,source_module',
            'creator:id,name',
            'validator:id,name',
            'payments' => fn ($query) => $query->where('status', 'COMPLETED')->latest('paid_at'),
            'payments.method:id,name',
            'payments.receipt:id,payment_id,receipt_number',
        ]);

        $canPay = $request->user()->can('payments.create');

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'returnToCash' => $request->query('from') === 'cash'
                && $request->user()->can('cash.view'),
            'capabilities' => [
                'can_pay' => $canPay,
                'can_view_receipts' => $request->user()->can('receipts.view'),
            ],
            'paymentMethods' => $canPay
                ? PaymentMethod::query()->where('active', true)->orderBy('id')->get(['id', 'code', 'name', 'category', 'affects_cash_balance', 'requires_reference'])
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
                : [],
            'openCashSessions' => $canPay
                ? CashSession::query()
                    ->where('status', CashSessionStatus::Open->value)
                    ->where('opened_by', $request->user()->id)
                    ->whereNotNull('active_key')
                    ->with(['register:id,uuid,name', 'register.acceptedPaymentMethods:id'])
                    ->get(['uuid', 'session_number', 'opened_at', 'cash_register_id'])
                    ->map(fn (CashSession $s) => [
                        'uuid' => $s->uuid,
                        'session_number' => $s->session_number,
                        'opened_at' => $s->opened_at,
                        'register_uuid' => $s->register?->uuid,
                        'register_name' => $s->register?->name,
                        // Empty means this desk accepts every active tender.
                        'accepted_payment_method_ids' => $s->register
                            ? $s->register->acceptedPaymentMethods->pluck('id')->all()
                            : [],
                    ])
                : [],
        ]);
    }

    public function store(
        StoreInvoiceRequest $request,
        Patient $patient,
        CreateInvoiceAction $action,
    ): RedirectResponse {
        $invoice = $action->execute($patient, $request->validated(), $request->user());

        return back()->with('status', "Facture {$invoice->invoice_number} créée en brouillon.");
    }

    public function validateInvoice(
        Request $request,
        Invoice $invoice,
        ValidateInvoiceAction $action,
    ): RedirectResponse {
        $invoice = $action->execute($invoice, $request->user());

        $message = $invoice->status === InvoiceStatus::Covered
            ? "Facture {$invoice->invoice_number} validée : prise en charge intégrale, aucun encaissement patient."
            : "Facture {$invoice->invoice_number} validée et prête à encaisser.";

        return back()->with('status', $message);
    }
}
