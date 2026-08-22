<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\CompleteEpisodeServicesAction;
use App\Enums\ArrivalPaymentChoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEpisodeServicesRequest;
use App\Models\CashSession;
use App\Models\Episode;
use App\Models\PaymentMethod;
use App\Services\Billing\BillableCatalogDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EpisodeServiceController extends Controller
{
    public function show(
        Request $request,
        Episode $episode,
        BillableCatalogDirectory $catalog,
    ): Response|RedirectResponse {
        $episode->load([
            'patient:id,uuid,patient_number,patient_type,first_name,last_name',
            'patient.activeMutualCoverage:id,patient_id,mutual_organization_id,membership_number,effective_until',
            'patient.activeMutualCoverage.organization:id,uuid,name',
        ]);

        if ($episode->service_plan_finalized_at) {
            return redirect()->route('patients.show', $episode->patient)
                ->with('status', "Le parcours du passage {$episode->episode_number} est déjà confirmé.");
        }

        $billingCatalog = $catalog->services($episode->patient);
        $tariffCategory = $billingCatalog->first()['tariff_category']
            ?? ($episode->patient->patient_type->value === 'MUTUAL' ? 'MUTUAL' : 'STANDARD');

        return Inertia::render('Reception/EpisodeServices', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'administrative_status' => $episode->administrative_status->value,
                'designation_deferred' => $episode->designation_deferred,
                'service_plan_finalized_at' => $episode->service_plan_finalized_at,
                'started_at' => $episode->started_at,
                'patient' => [
                    'uuid' => $episode->patient->uuid,
                    'patient_number' => $episode->patient->patient_number,
                    'patient_type' => $episode->patient->patient_type->value,
                    'first_name' => $episode->patient->first_name,
                    'last_name' => $episode->patient->last_name,
                    'mutual_coverage' => $episode->patient->activeMutualCoverage ? [
                        'organization_name' => $episode->patient->activeMutualCoverage->organization->name,
                        'membership_number' => $episode->patient->activeMutualCoverage->membership_number,
                    ] : null,
                ],
            ],
            'billingCatalog' => $billingCatalog,
            'pricingContext' => [
                'category' => $tariffCategory,
                'label' => $tariffCategory === 'MUTUAL' ? 'Tarif mutuelle' : 'Tarif sans mutuelle',
                'organization_name' => $episode->patient->activeMutualCoverage?->organization?->name,
                'missing_tariffs_count' => $billingCatalog->where('tariff_available', false)->count(),
            ],
            'paymentMethods' => $request->user()->can('payments.create')
                ? PaymentMethod::query()->where('active', true)->orderBy('id')->get(['id', 'code', 'name'])
                : [],
            'openCashSession' => $request->user()->can('payments.create')
                ? CashSession::query()->where('active_key', 'SINGLE_OPEN_CASH')->first(['uuid', 'session_number', 'opened_at'])
                : null,
        ]);
    }

    public function store(
        StoreEpisodeServicesRequest $request,
        Episode $episode,
        CompleteEpisodeServicesAction $action,
    ): RedirectResponse {
        $result = $action->execute(
            episode: $episode->load('patient'),
            actor: $request->user(),
            catalogLines: $request->validated('catalog_lines', []),
            designationDeferred: $request->boolean('defer_designation'),
            paymentChoice: ArrivalPaymentChoice::tryFrom((string) $request->validated('payment_choice'))
                ?? ArrivalPaymentChoice::Later,
            paymentMethodId: $request->integer('payment_method_id') ?: null,
            paymentReference: $request->validated('payment_reference'),
        );

        $message = "Parcours du passage {$episode->episode_number} confirmé.";

        if ($result->billingWarning) {
            return redirect()->route('patients.show', $episode->patient)
                ->with('status', "{$message} {$result->billingWarning}");
        }

        if ($result->payment && $request->user()->can('receipts.view')) {
            return redirect()->route('receipts.show', $result->payment->receipt)
                ->with('status', "{$message} Paiement enregistré et reçu généré.");
        }

        if ($result->invoice && $request->user()->can('billing.print')) {
            return redirect()->route('invoices.show', $result->invoice)
                ->with('status', "{$message} Facture {$result->invoice->invoice_number} créée.");
        }

        return redirect()->route('patients.show', $episode->patient)
            ->with('status', $message);
    }
}
