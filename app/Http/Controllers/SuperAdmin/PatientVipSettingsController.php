<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seuils des patients VIP, site par site (ADR-133). Le portail ne lit ni
 * n'écrit jamais une base clinique : chaque appel passe par l'API du site.
 */
class PatientVipSettingsController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        return Inertia::render('SuperAdmin/PatientVip/Index', [
            'sites' => $client->patientVipSettingsForAllSites($request->user()),
        ]);
    }

    public function preview(Request $request, PortalSiteApiClient $client): JsonResponse
    {
        $validated = $this->validated($request);
        $result = $client->previewPatientVipSettings($validated['site_code'], $this->payload($validated), $request->user());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function update(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $this->validated($request);
        $result = $client->updatePatientVipSettings($validated['site_code'], $this->payload($validated), $request->user());

        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: ['site_code' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: 'Seuils enregistrés.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'site_code' => ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())],
            'enabled' => ['required', 'boolean'],
            'min_episodes' => ['required', 'integer', 'min:1', 'max:1000'],
            'min_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'window_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return collect($validated)->except('site_code')->all();
    }
}
