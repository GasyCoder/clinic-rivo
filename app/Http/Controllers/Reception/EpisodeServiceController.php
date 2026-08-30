<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\CompleteEpisodeServicesAction;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\CashSessionStatus;
use App\Enums\EpisodeFinancialMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEpisodeServicesRequest;
use App\Models\CashSession;
use App\Models\Episode;
use App\Models\PaymentMethod;
use App\Services\Billing\BillableCatalogDirectory;
use App\Services\Finance\StaffBlockCreditLedger;
use App\Support\Money;
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
        StaffBlockCreditLedger $staffBlockCredits,
    ): Response|RedirectResponse {
        $episode->load([
            'patient:id,uuid,patient_number,patient_type,first_name,last_name',
            'mutualCoverage:id,episode_id,mutual_organization_id,membership_number,organization_name_snapshot,coverage_rate_snapshot',
            'staffCoverage:id,episode_id,employee_id',
            'staffCoverage.employee:id,uuid,employee_number,first_name,last_name',
        ]);

        if ($episode->service_plan_finalized_at) {
            return redirect()->route('patients.show', $episode->patient)
                ->with('status', "Le parcours du passage {$episode->episode_number} est déjà confirmé.");
        }

        $billingCatalog = $catalog->services($episode);
        $tariffCategory = $billingCatalog->first()['tariff_category']
            ?? match ($episode->financial_mode) {
                EpisodeFinancialMode::Mutual => 'MUTUAL',
                EpisodeFinancialMode::Self, EpisodeFinancialMode::Staff, EpisodeFinancialMode::Partner => 'STANDARD',
                null => null,
            };

        return Inertia::render('Reception/EpisodeServices', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'financial_mode' => $episode->financial_mode?->value,
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
                    'mutual_coverage' => $episode->mutualCoverage ? [
                        'organization_name' => $episode->mutualCoverage->organization_name_snapshot,
                        'coverage_rate' => $episode->mutualCoverage->coverage_rate_snapshot,
                        'membership_number' => $episode->mutualCoverage->membership_number,
                    ] : null,
                ],
            ],
            'billingCatalog' => $billingCatalog,
            'pricingContext' => [
                'category' => $tariffCategory,
                'financial_mode' => $episode->financial_mode?->value,
                'label' => match ($tariffCategory) {
                    'MUTUAL' => 'Tarif mutuelle',
                    'STANDARD' => $episode->financial_mode === EpisodeFinancialMode::Staff
                        ? 'Avantage Personnel · tarif brut Sans mutuelle'
                        : 'Tarif sans mutuelle',
                    default => 'Contexte à régulariser',
                },
                'organization_name' => $episode->mutualCoverage?->organization_name_snapshot,
                'coverage_rate' => $episode->mutualCoverage?->coverage_rate_snapshot,
                'patient_rate' => $episode->mutualCoverage
                    ? Money::fromMinor(10_000 - Money::toMinor(
                        $episode->mutualCoverage->coverage_rate_snapshot,
                    ))
                    : ($episode->financial_mode === EpisodeFinancialMode::Self ? '100.00' : null),
                'missing_tariffs_count' => $billingCatalog->where('tariff_available', false)->count(),
                'unclassified_staff_items_count' => $episode->financial_mode === EpisodeFinancialMode::Staff
                    ? $billingCatalog->where('staff_coverage_policy', 'UNCLASSIFIED')->count()
                    : 0,
                'staff_block_credit' => $episode->financial_mode === EpisodeFinancialMode::Staff
                    && $episode->staffCoverage?->employee
                        ? $staffBlockCredits->summary($episode->staffCoverage->employee)
                        : null,
            ],
            'paymentMethods' => $request->user()->can('payments.create')
                ? PaymentMethod::query()->where('active', true)->orderBy('id')->get(['id', 'code', 'name'])
                : [],
            'openCashSessions' => $request->user()->can('payments.create')
                ? CashSession::query()
                    ->where('status', CashSessionStatus::Open->value)
                    ->where('opened_by', $request->user()->id)
                    ->whereNotNull('active_key')
                    ->with('register:id,uuid,name')
                    ->get(['uuid', 'session_number', 'opened_at', 'cash_register_id'])
                    ->map(fn (CashSession $s) => [
                        'uuid' => $s->uuid,
                        'session_number' => $s->session_number,
                        'opened_at' => $s->opened_at,
                        'register_uuid' => $s->register?->uuid,
                        'register_name' => $s->register?->name,
                    ])
                : [],
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
            cashRegisterUuid: $request->validated('cash_register_uuid'),
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
