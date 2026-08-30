<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashRegisterController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
        ]);
        $filters['status'] ??= 'ACTIVE';

        return Inertia::render('SuperAdmin/CashRegisters/Index', [
            'sites' => $client->cashRegistersForAllSites($request->user(), array_filter($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'name' => ['required', 'string', 'max:255'],
        ]);

        return $this->respond(
            $client->createCashRegister($validated['site_code'], $validated['name'], $request->user()),
            'Caisse ajoutée au référentiel du site.',
        );
    }

    public function show(
        Request $request,
        string $site,
        string $cashRegister,
        PortalSiteApiClient $client,
    ): Response {
        $result = $client->cashRegisterProfile($site, $cashRegister, $request->user());

        return Inertia::render('SuperAdmin/CashRegisters/Show', [
            'targetSite' => $result['site'],
            'profile' => $result['ok'] ? $result['data'] : null,
            'apiState' => [
                'ok' => $result['ok'],
                'status' => $result['status'],
                'message' => $result['message'],
            ],
        ]);
    }

    public function export(
        Request $request,
        string $site,
        string $cashRegister,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): StreamedResponse {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['movements', 'sessions'])],
        ]);

        $result = $client->cashRegisterProfile($site, $cashRegister, $request->user());

        abort_if(! $result['ok'], 503, 'Le site ne peut pas transmettre cette fiche actuellement.');

        $registerName = data_get($result, 'data.register.name', $cashRegister);
        $scope = Str::slug($site.'-'.$registerName);
        $timestamp = now()->format('Y-m-d-His');

        if ($validated['type'] === 'movements') {
            $rows = collect(data_get($result, 'data.movements', []))->map(fn (array $movement) => [
                $movement['occurred_at'],
                $movement['description'],
                $movement['type'],
                $movement['payment_number'],
                $movement['payment_method'],
                $movement['recorded_by'],
                $movement['direction'],
                $movement['amount'],
            ]);

            return $excel->download(
                'mouvements-'.$scope.'-'.$timestamp,
                'Mouvements de caisse',
                ['Date/heure', 'Description', 'Type', 'Référence paiement', 'Mode de paiement', 'Agent', 'Sens', 'Montant'],
                $rows,
            );
        }

        $rows = collect(data_get($result, 'data.recent_sessions', []))->map(fn (array $session) => [
            $session['session_number'],
            $session['status'],
            $session['opened_by'],
            $session['opened_at'],
            $session['closed_by'],
            $session['closed_at'],
            $session['opening_amount'],
            $session['actual_closing_amount'],
            $session['variance_amount'],
        ]);

        return $excel->download(
            'historique-sessions-'.$scope.'-'.$timestamp,
            'Historique des sessions',
            ['Session', 'Statut', 'Ouverte par', 'Ouverte le', 'Clôturée par', 'Clôturée le', 'Fond initial', 'Compté', 'Écart'],
            $rows,
        );
    }

    public function update(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return $this->respond(
            $client->updateCashRegister($site, $cashRegister, $validated['name'], $request->user()),
            'Caisse mise à jour.',
        );
    }

    public function activate(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->activateCashRegister($site, $cashRegister, $request->user()),
            'Caisse activée.',
        );
    }

    public function deactivate(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->deactivateCashRegister($site, $cashRegister, $request->user()),
            'Caisse désactivée.',
        );
    }

    public function destroy(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond(
            $client->archiveCashRegister($site, $cashRegister, $validated['reason'], $request->user()),
            'Caisse archivée.',
        );
    }

    public function restore(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->restoreCashRegister($site, $cashRegister, $request->user()),
            'Caisse restaurée.',
        );
    }

    public function lock(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->respond(
            $client->lockCashRegisterSession($site, $cashRegister, $validated['reason'], $request->user()),
            'Session verrouillée.',
        );
    }

    public function unlock(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->respond(
            $client->unlockCashRegisterSession($site, $cashRegister, $validated['reason'], $request->user()),
            'Session déverrouillée.',
        );
    }

    public function close(Request $request, string $site, string $cashRegister, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'actual_closing_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return $this->respond(
            $client->closeCashRegisterSession(
                $site,
                $cashRegister,
                (string) $validated['actual_closing_amount'],
                $validated['reason'],
                $request->user(),
            ),
            'Session clôturée.',
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
