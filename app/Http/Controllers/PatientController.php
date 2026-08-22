<?php

namespace App\Http\Controllers;

use App\Actions\Patient\DeletePatientsAction;
use App\Actions\Patient\UpdatePatientAction;
use App\Enums\BillableItemStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Http\Requests\BulkDeletePatientsRequest;
use App\Http\Requests\DeletePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Models\BillableItem;
use App\Models\CashSession;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Services\Billing\BillableCatalogDirectory;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient directory and administrative record management. Creating a
 * patient remains Réception's job (§5.2.1): a patient is never created
 * outside the context of an arrival/passage.
 */
class PatientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $patients = Patient::query()
            ->withCount([
                'episodes as active_emergency_episodes_count' => fn ($query) => $query
                    ->where('priority', EpisodePriority::Emergency->value)
                    ->where('status', EpisodeStatus::Open->value),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Patients/Index', [
            'patients' => $patients,
            'search' => $search,
        ]);
    }

    public function show(Request $request, Patient $patient, BillableCatalogDirectory $catalog): Response
    {
        $patient->load([
            'antecedents',
            'allergies',
            'episodes' => fn ($query) => $query
                ->with('orientations:id,episode_id,destination_module,status,oriented_at,accepted_at,completed_at')
                ->orderByDesc('started_at'),
        ]);

        $account = null;
        $paymentMethods = [];
        $openCashSession = null;
        $billingCatalog = [];

        if ($request->user()->can('billing.view')) {
            $canViewPayments = $request->user()->can('payments.view');
            $patient->load([
                'invoices' => fn ($query) => $query->latest(),
                'invoices.episode:id,uuid,episode_number',
                'invoices.lines.billableItem:id,source_module',
                ...($canViewPayments ? [
                    'invoices.payments' => fn ($query) => $query
                        ->latest('paid_at'),
                    'invoices.payments.method:id,name',
                    'invoices.payments.receipt:id,uuid,payment_id,receipt_number',
                    'invoices.payments.cashier:id,name',
                    'invoices.payments.canceller:id,name',
                    'invoices.payments.cashSession:id,uuid',
                ] : []),
            ]);

            $activeInvoices = $patient->invoices->where('status', '!=', InvoiceStatus::Cancelled);
            $billableItems = BillableItem::query()
                ->whereIn('episode_id', $patient->episodes->pluck('id'))
                ->with('episode:id,uuid,episode_number')
                ->latest()
                ->get();
            $pendingItems = $billableItems->where('status', BillableItemStatus::Pending);

            $account = [
                'total_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->total_amount))),
                'paid_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->paid_amount))),
                'balance_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->balance_amount))),
                'unbilled_amount' => Money::fromMinor($pendingItems->sum(fn ($item) => Money::toMinor($item->total_amount))),
                'billable_items' => $billableItems->map(fn ($item) => [
                    'uuid' => $item->uuid,
                    'source_module' => $item->source_module,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total_amount,
                    'currency' => $item->currency,
                    'payment_required_before_fulfillment' => $item->payment_required_before_fulfillment,
                    'status' => $item->status->value,
                    'created_at' => $item->created_at,
                    'cancelled_at' => $item->cancelled_at,
                    'cancellation_reason' => $item->cancellation_reason,
                    'episode' => [
                        'uuid' => $item->episode->uuid,
                        'episode_number' => $item->episode->episode_number,
                    ],
                ])->values(),
                'invoices' => $patient->invoices->map(fn ($invoice) => [
                    'uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status->value,
                    'currency' => $invoice->currency,
                    'subtotal_amount' => $invoice->subtotal_amount,
                    'discount_amount' => $invoice->discount_amount,
                    'total_amount' => $invoice->total_amount,
                    'paid_amount' => $invoice->paid_amount,
                    'balance_amount' => $invoice->balance_amount,
                    'created_at' => $invoice->created_at,
                    'validated_at' => $invoice->validated_at,
                    'episode' => [
                        'uuid' => $invoice->episode->uuid,
                        'episode_number' => $invoice->episode->episode_number,
                    ],
                    'lines' => $invoice->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'line_total' => $line->line_total,
                        'source_module' => $line->billableItem?->source_module ?? 'RECEPTION',
                        'status' => $line->status,
                    ])->values(),
                    'payments' => $canViewPayments
                        ? $invoice->payments->map(fn ($payment) => [
                            'uuid' => $payment->uuid,
                            'payment_number' => $payment->payment_number,
                            'amount' => $payment->amount,
                            'currency' => $payment->currency,
                            'reference' => $payment->reference,
                            'status' => $payment->status->value,
                            'paid_at' => $payment->paid_at,
                            'cancelled_at' => $payment->cancelled_at,
                            'cancellation_reason' => $payment->cancellation_reason,
                            'method' => $payment->method->name,
                            'cashier' => $payment->cashier->name,
                            'canceller' => $payment->canceller?->name,
                            'cash_session_uuid' => $payment->cashSession->uuid,
                            'receipt' => $payment->receipt ? [
                                'uuid' => $payment->receipt->uuid,
                                'receipt_number' => $payment->receipt->receipt_number,
                            ] : null,
                        ])->values()
                        : [],
                ])->values(),
            ];
        }

        if ($request->user()->can('payments.create')) {
            $paymentMethods = PaymentMethod::query()
                ->where('active', true)
                ->orderBy('id')
                ->get(['id', 'code', 'name']);
        }

        if ($request->user()->can('payments.create') || $request->user()->can('payments.cancel')) {
            $openCashSession = CashSession::query()
                ->where('active_key', 'SINGLE_OPEN_CASH')
                ->first(['uuid', 'session_number', 'opened_at']);
        }

        if ($request->user()->can('billing.create')) {
            $billingCatalog = $catalog->services();
        }

        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'account' => $account,
            'paymentMethods' => $paymentMethods,
            'openCashSession' => $openCashSession,
            'billingCatalog' => $billingCatalog,
        ]);
    }

    public function edit(Patient $patient): Response
    {
        return Inertia::render('Patients/Edit', [
            'patient' => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age,
                'sex' => $patient->sex->value,
                'civility' => $patient->civility?->value,
                'identity_document_type' => $patient->identity_document_type?->value,
                'identity_document_number' => $patient->identity_document_number,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address' => $patient->address,
                'emergency_contact_name' => $patient->emergency_contact_name,
                'emergency_contact_phone' => $patient->emergency_contact_phone,
                'emergency_contact_relationship' => $patient->emergency_contact_relationship,
                'emergency_contact_email' => $patient->emergency_contact_email,
            ],
        ]);
    }

    public function update(
        UpdatePatientRequest $request,
        Patient $patient,
        UpdatePatientAction $action,
    ): RedirectResponse {
        $action->execute($patient, $request->validated());

        return redirect()->route('patients.show', $patient)
            ->with('status', "Dossier {$patient->patient_number} mis à jour.");
    }

    public function destroy(
        DeletePatientRequest $request,
        Patient $patient,
        DeletePatientsAction $action,
    ): RedirectResponse {
        $action->execute([$patient], $request->validated('reason'));

        return back()
            ->with('status', "Dossier {$patient->patient_number} archivé.");
    }

    public function bulkDestroy(
        BulkDeletePatientsRequest $request,
        DeletePatientsAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $patients = Patient::query()->whereIn('uuid', $validated['patient_uuids'])->get();
        $deleted = $action->execute($patients, $validated['reason']);

        return back()
            ->with('status', "{$deleted} dossier(s) patient archivé(s).");
    }
}
