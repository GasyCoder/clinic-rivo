<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-164 — le portail règle les services, chambres et lits de chaque site.
 *
 * Il n'ouvre aucune connexion vers une base clinique (ADR-004) : chaque
 * écriture part vers l'API du site, qui revérifie le droit, refuse ce qui
 * laisserait un patient sans lit et audite avec l'identité centrale.
 */
class HospitalBedController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $archived = $request->boolean('archived');

        return Inertia::render('SuperAdmin/HospitalBeds/Index', [
            'sites' => $client->hospitalBedsForAllSites($request->user(), ['archived' => $archived ? 1 : 0]),
            'filters' => ['archived' => $archived],
        ]);
    }

    public function storeService(Request $request, string $site, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'services', $this->service($request));
    }

    public function updateService(Request $request, string $site, string $service, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'PUT', 'services/'.$service, $this->service($request));
    }

    public function archiveService(Request $request, string $site, string $service, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'DELETE', 'services/'.$service, $this->reason($request));
    }

    public function restoreService(Request $request, string $site, string $service, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'services/'.$service.'/restore', []);
    }

    public function storeRoom(Request $request, string $site, string $service, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'bed_count' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        return $this->send($client, $request, $site, 'POST', 'services/'.$service.'/rooms', $validated);
    }

    public function updateRoom(Request $request, string $site, string $room, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return $this->send($client, $request, $site, 'PUT', 'rooms/'.$room, $validated);
    }

    public function addBeds(Request $request, string $site, string $room, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:30']]);

        return $this->send($client, $request, $site, 'POST', 'rooms/'.$room.'/beds', $validated);
    }

    public function archiveRoom(Request $request, string $site, string $room, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'DELETE', 'rooms/'.$room, $this->reason($request));
    }

    public function restoreRoom(Request $request, string $site, string $room, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'rooms/'.$room.'/restore', []);
    }

    public function updateBed(Request $request, string $site, string $bed, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:50']]);

        return $this->send($client, $request, $site, 'PUT', 'beds/'.$bed, $validated);
    }

    public function outOfService(Request $request, string $site, string $bed, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'beds/'.$bed.'/out-of-service', $this->reason($request));
    }

    public function inService(Request $request, string $site, string $bed, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'beds/'.$bed.'/in-service', []);
    }

    public function archiveBed(Request $request, string $site, string $bed, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'DELETE', 'beds/'.$bed, $this->reason($request));
    }

    public function restoreBed(Request $request, string $site, string $bed, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->send($client, $request, $site, 'POST', 'beds/'.$bed.'/restore', []);
    }

    /** @return array{name: string, care_level: string} */
    private function service(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'care_level' => ['required', Rule::in(['STANDARD', 'CONTINUOUS', 'INTENSIVE'])],
        ]);
    }

    /** @return array{reason: string} */
    private function reason(Request $request): array
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
    }

    /** @param array<string, mixed> $payload */
    private function send(PortalSiteApiClient $client, Request $request, string $site, string $method, string $path, array $payload): RedirectResponse
    {
        $result = $client->hospitalBedCommand($site, $method, $path, $payload, $request->user());

        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: ['site' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: 'Référentiel des lits mis à jour.');
    }
}
