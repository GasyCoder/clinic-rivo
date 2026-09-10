<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\PaymentMethodCategory;
use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
        ]);
        $filters['status'] ??= 'ALL';

        return Inertia::render('SuperAdmin/PaymentMethods/Index', [
            'sites' => $client->paymentMethodsForAllSites($request->user(), array_filter($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(PaymentMethodCategory::class)],
            'affects_cash_balance' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
        ]);

        return $this->respond(
            $client->createPaymentMethod($validated['site_code'], [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'category' => $validated['category'],
                'affects_cash_balance' => $validated['affects_cash_balance'],
                'requires_reference' => $validated['requires_reference'],
            ], $request->user()),
            'Mode de paiement ajouté au référentiel du site.',
        );
    }

    public function update(Request $request, string $site, string $paymentMethod, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(PaymentMethodCategory::class)],
            'affects_cash_balance' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
        ]);

        return $this->respond(
            $client->updatePaymentMethod($site, $paymentMethod, $validated, $request->user()),
            'Mode de paiement mis à jour.',
        );
    }

    public function activate(Request $request, string $site, string $paymentMethod, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->activatePaymentMethod($site, $paymentMethod, $request->user()),
            'Mode de paiement activé.',
        );
    }

    public function deactivate(Request $request, string $site, string $paymentMethod, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->deactivatePaymentMethod($site, $paymentMethod, $request->user()),
            'Mode de paiement désactivé.',
        );
    }

    /** @return array<int, mixed> */
    private function siteCodeRules(): array
    {
        return ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())];
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result, string $successMessage): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: ['site_code' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: $successMessage);
    }
}
