<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\SiteScreenGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as SiteResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-187 / ADR-189 — un espace d'un site (les RH, la Pharmacie), vu et géré
 * depuis le portail (CDC §2, §18).
 *
 * Le Super Admin voit et fait ce que l'espace du site propose : les mêmes
 * écrans Vue, servis par le site à travers son API, avec ses règles et son
 * audit. Le portail ne décide de rien : il relaie, et ramène les adresses du
 * site vers les siennes.
 */
abstract class SiteScreenController extends Controller
{
    /** Le nom de la prop qui dit à l'écran qu'il est ouvert sur le portail. */
    abstract protected function contextKey(): string;

    /** La page du portail qui réunit tous les sites pour cet espace. */
    abstract protected function overviewRoute(): string;

    protected function relay(Request $request, string $site, SiteScreenGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        $target = $gateway->site($site);
        $base = $gateway->base($target);
        $path = $path !== '' ? $path : $gateway->landingPath();

        if (! $gateway->configured($target)) {
            return $this->unavailable($request, "L’URL ou le jeton API de {$target['name']} n’est pas configuré.");
        }

        try {
            $response = $gateway->forward(
                $target,
                $request->user(),
                $request->method(),
                $path,
                $request->query(),
                $request->method() === 'GET' ? [] : Arr::except($request->post(), ['_method', '_token']),
                $request->allFiles(),
                $gateway->sitePathFrom($request->headers->get('referer'), $base),
            );
        } catch (ConnectionException) {
            return $this->unavailable($request, "{$target['name']} ne répond pas. Les autres sites restent disponibles.");
        }

        if ($this->isDataCall($request) && ! $this->isFile($response)) {
            // Un aperçu (congé, document) lu par `fetch()` : on rend la
            // réponse du site telle quelle, statut compris.
            return new JsonResponse($response->json() ?? [], $response->status());
        }

        if ($response->status() === 422) {
            return back()->withErrors($this->errors($response))->withInput();
        }

        if (! $response->successful()) {
            return $this->refused($request, $response);
        }

        if ($this->isFile($response)) {
            return $this->file($response);
        }

        $json = $response->json() ?? [];

        if (array_key_exists('redirect', $json)) {
            return $this->redirect($json, $gateway, $base);
        }

        if (array_key_exists('component', $json)) {
            abort_unless($gateway->isScreen($json['component']), 404);

            return $this->screen($json, $gateway, $target, $base);
        }

        return new JsonResponse($json);
    }

    /** @param array<string, mixed> $page */
    private function screen(array $page, SiteScreenGateway $gateway, array $target, string $base): InertiaResponse
    {
        return Inertia::render($page['component'], [
            ...$gateway->portalProps($page['props'] ?? [], $base),
            $this->contextKey() => [
                'base' => $base,
                'site' => ['code' => $target['code'], 'name' => $target['name']],
                'sites' => collect(config('rivo.clinics', []))->map(fn (array $site) => [
                    'code' => $site['code'],
                    'name' => $site['name'],
                    'url' => $gateway->base($site),
                    'configured' => $gateway->configured($site),
                ])->values()->all(),
                'overview_url' => route($this->overviewRoute()),
            ],
        ]);
    }

    /** @param array<string, mixed> $json */
    private function redirect(array $json, SiteScreenGateway $gateway, string $base): RedirectResponse
    {
        $target = $gateway->portalUrl((string) $json['redirect'], $base);

        // Une redirection hors de l'espace ramène à son accueil.
        if ($target !== $base && ! str_starts_with($target, $base.'/') && ! str_starts_with($target, $base.'?')) {
            $target = $base;
        }

        $redirect = redirect($target);

        if (filled($json['status'] ?? null)) {
            $redirect->with('status', $json['status']);
        }

        if (filled($json['error'] ?? null)) {
            $redirect->with('error', $json['error']);
        }

        return $redirect;
    }

    private function refused(Request $request, SiteResponse $response): SymfonyResponse
    {
        $message = (string) ($response->json('message') ?: 'Le site a refusé cette demande.');

        if ($request->method() === 'GET') {
            abort($response->status() >= 500 ? 502 : $response->status(), $message);
        }

        return back()->with('error', $message);
    }

    private function unavailable(Request $request, string $message): SymfonyResponse
    {
        if ($this->isDataCall($request)) {
            return new JsonResponse(['message' => $message], 503);
        }

        if ($request->method() !== 'GET') {
            return back()->with('error', $message);
        }

        return redirect()->route($this->overviewRoute())->with('error', $message);
    }

    /** @return array<string, string> */
    private function errors(SiteResponse $response): array
    {
        $errors = collect($response->json('errors') ?? [])
            ->map(fn (mixed $messages) => is_array($messages) ? (string) reset($messages) : (string) $messages)
            ->filter()
            ->all();

        return $errors ?: ['site' => (string) ($response->json('message') ?: 'Le site a refusé ces données.')];
    }

    private function file(SiteResponse $response): Response
    {
        $headers = array_filter([
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Content-Disposition' => $response->header('Content-Disposition') ?: null,
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return new Response($response->body(), 200, $headers);
    }

    private function isFile(SiteResponse $response): bool
    {
        return ! str_contains(strtolower((string) $response->header('Content-Type')), 'json');
    }

    /** Un appel `fetch()` qui attend des données, pas une visite Inertia. */
    private function isDataCall(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
