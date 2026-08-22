<?php

namespace App\Http\Controllers;

use App\Actions\Reception\CompletePatientArrivalAction;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionPatientStep;
use App\Exceptions\DuplicatePatientException;
use App\Http\Requests\StoreArrivalRequest;
use App\Models\CashSession;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\VisitorVisit;
use App\Services\Billing\BillableCatalogDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reception entry point. The operational desk now has two deliberately
 * separate paths: clinical patient arrivals and non-clinical visitors.
 */
class ReceptionController extends Controller
{
    public function index(Request $request): Response
    {
        $recentEpisodes = $request->user()->can('episodes.view')
            ? Episode::query()
                ->with('patient:id,uuid,patient_number,first_name,last_name')
                ->latest('started_at')
                ->limit(8)
                ->get(['id', 'patient_id', 'episode_number', 'status', 'priority', 'administrative_status', 'started_at'])
            : collect();

        $presentVisitors = $request->user()->can('visitors.view')
            ? VisitorVisit::query()
                ->with('patient:id,uuid,patient_number,first_name,last_name')
                ->whereNull('checked_out_at')
                ->latest('checked_in_at')
                ->limit(8)
                ->get()
            : collect();

        return Inertia::render('Reception/Index', [
            'recentEpisodes' => $recentEpisodes,
            'presentVisitors' => $presentVisitors,
        ]);
    }

    public function patients(Request $request, BillableCatalogDirectory $catalog): Response|RedirectResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            return redirect()->route('reception.patients.step', [
                'step' => ReceptionPatientStep::Identity->value,
                'q' => $search,
            ]);
        }

        return $this->renderPatientReception($request, $catalog);
    }

    public function patientStep(
        Request $request,
        BillableCatalogDirectory $catalog,
        string $step,
    ): Response {
        return $this->renderPatientReception(
            $request,
            $catalog,
            ReceptionPatientStep::from($step),
        );
    }

    private function renderPatientReception(
        Request $request,
        BillableCatalogDirectory $catalog,
        ?ReceptionPatientStep $step = null,
    ): Response {
        $search = trim((string) $request->query('q', ''));
        $recentFilter = in_array($request->query('filter'), ['normal', 'emergency', 'pending', 'oriented'], true)
            ? $request->query('filter')
            : 'all';

        $matches = $search !== ''
            ? Patient::query()
                ->where(function ($query) use ($search) {
                    $query->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->limit(10)
                ->get()
                ->map(fn (Patient $patient) => [
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
                ])
            : collect();

        // "identifier les patients présents" / "consulter le statut du
        // parcours patient" (CDC §5.2.1) — recent activity, not filtered to
        // today only: the receptionist also needs to see who's still mid-
        // passage from a day or two ago.
        $recentEpisodes = Episode::query()
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name',
                'orientations:id,episode_id,destination_module,status,oriented_at,accepted_at,completed_at',
            ])
            ->when(
                $recentFilter === 'normal',
                fn ($query) => $query->where('priority', EpisodePriority::Normal->value),
            )
            ->when(
                $recentFilter === 'emergency',
                fn ($query) => $query->where('priority', EpisodePriority::Emergency->value),
            )
            ->when(
                $recentFilter === 'pending',
                fn ($query) => $query->whereHas('orientations', fn ($orientation) => $orientation
                    ->where('destination_module', CatalogModule::Care->value)
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                    ]))->whereDoesntHave('orientations', fn ($orientation) => $orientation
                    ->where('destination_module', CatalogModule::Medicine->value)
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                        EpisodeOrientationStatus::Completed->value,
                    ])),
            )
            ->when(
                $recentFilter === 'oriented',
                fn ($query) => $query->whereHas('orientations', fn ($orientation) => $orientation
                    ->where('destination_module', CatalogModule::Medicine->value)
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                        EpisodeOrientationStatus::Completed->value,
                    ])),
            )
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['id', 'uuid', 'patient_id', 'episode_number', 'status', 'priority', 'administrative_status', 'started_at']);

        return Inertia::render('Reception/Create', [
            'step' => $step?->value,
            'search' => $search,
            'matches' => $matches,
            'recentEpisodes' => $recentEpisodes,
            'recentEpisodeFilter' => $recentFilter,
            'billingCatalog' => $request->user()->can('billing.create')
                ? $catalog->services()
                : [],
            'paymentMethods' => $request->user()->can('payments.create')
                ? PaymentMethod::query()
                    ->where('active', true)
                    ->orderBy('id')
                    ->get(['id', 'code', 'name'])
                : [],
            'openCashSession' => $request->user()->can('payments.create')
                ? CashSession::query()
                    ->where('active_key', 'SINGLE_OPEN_CASH')
                    ->first(['uuid', 'session_number', 'opened_at'])
                : null,
        ]);
    }

    public function storePatient(StoreArrivalRequest $request, CompletePatientArrivalAction $action): RedirectResponse
    {
        try {
            $patientData = $request->safe()->only([
                'first_name',
                'last_name',
                'birth_date',
                'age',
                'sex',
                'civility',
                'identity_document_type',
                'identity_document_number',
                'phone',
                'email',
                'address',
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_email',
                'emergency_contact_relationship',
            ]);

            $result = $action->execute(
                actor: $request->user(),
                existingPatientUuid: $request->input('patient_uuid'),
                newPatientData: $request->filled('patient_uuid')
                    ? null
                    : $patientData,
                existingPatientData: $request->filled('patient_uuid') && $request->boolean('update_patient')
                    ? $patientData
                    : null,
                confirmDuplicate: $request->boolean('confirm_duplicate'),
                priority: $request->boolean('is_emergency')
                    ? EpisodePriority::Emergency
                    : EpisodePriority::Normal,
                catalogLines: $request->validated('catalog_lines', []),
                paymentChoice: ArrivalPaymentChoice::tryFrom((string) $request->validated('payment_choice'))
                    ?? ArrivalPaymentChoice::Later,
                paymentMethodId: $request->integer('payment_method_id') ?: null,
                paymentReference: $request->validated('payment_reference'),
            );
        } catch (DuplicatePatientException $e) {
            return back()->withInput()->with('duplicates', $e->matches->map(fn (Patient $p) => [
                'uuid' => $p->uuid,
                'patient_number' => $p->patient_number,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'birth_date' => $p->birth_date->toDateString(),
            ])->all());
        }

        $episode = $result->episode;
        $message = $episode->priority === EpisodePriority::Emergency
            ? "Passage urgence {$episode->episode_number} créé et orienté vers Médecine / Soins."
            : "Passage {$episode->episode_number} créé.";

        if ($result->billingWarning) {
            $message .= " Admission conservée, mais la facturation n’a pas abouti : {$result->billingWarning}";
        }

        if ($result->payment) {
            $message .= " Facture {$result->invoice->invoice_number} réglée. Reçu {$result->payment->receipt->receipt_number} disponible.";

            if ($request->user()->can('receipts.view')) {
                return redirect()->route('receipts.show', $result->payment->receipt)
                    ->with('status', $message);
            }
        } elseif ($result->invoice) {
            $message .= " Facture {$result->invoice->invoice_number} créée avec un solde de {$result->invoice->balance_amount} MGA à payer.";

            if ($request->user()->can('billing.print')) {
                return redirect()->route('invoices.show', $result->invoice)
                    ->with('status', $message);
            }
        }

        return redirect()->route('patients.show', $episode->patient)
            ->with('status', $message);
    }
}
