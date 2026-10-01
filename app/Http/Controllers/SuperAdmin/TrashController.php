<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\TrashCategory;
use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $siteCodes = collect(config('rivo.clinics', []))->pluck('code')->all();
        $categoryCodes = array_column(TrashCategory::options(), 'code');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', Rule::in(['ALL', ...$siteCodes])],
            'category' => ['nullable', Rule::in(['ALL', ...$categoryCodes])],
            'deleted_from' => ['nullable', 'date_format:Y-m-d'],
            'deleted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deleted_from'],
        ]);
        $filters['site'] ??= 'ALL';
        $filters['category'] ??= 'ALL';
        $remoteFilters = collect($filters)
            ->only(['search', 'category', 'deleted_from', 'deleted_to'])
            ->filter(fn ($value) => filled($value))
            ->all();

        return Inertia::render('SuperAdmin/Trash/Index', [
            'sites' => $client->trashForAllSites($request->user(), $remoteFilters),
            'categories' => TrashCategory::options(),
            'filters' => $filters,
        ]);
    }

    public function restore(
        Request $request,
        string $site,
        string $category,
        string $uuid,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $validated = validator([
            'site' => $site,
            'category' => $category,
            'uuid' => $uuid,
        ], [
            'site' => ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())],
            'category' => ['required', Rule::enum(TrashCategory::class)],
            'uuid' => ['required', 'uuid'],
        ])->validate();
        $result = $client->restoreTrashItem(
            $validated['site'],
            $validated['category'],
            $validated['uuid'],
            $request->user(),
        );

        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [
                    $field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages,
                ],
            )->all();

            return back()->withErrors($errors ?: ['restore' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: 'Élément restauré.');
    }

    public function destroy(
        Request $request,
        string $site,
        string $category,
        string $uuid,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $validated = validator([
            'site' => $site,
            'category' => $category,
            'uuid' => $uuid,
        ], [
            'site' => ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())],
            'category' => ['required', Rule::enum(TrashCategory::class)],
            'uuid' => ['required', 'uuid'],
        ])->validate();
        $result = $client->forceDeleteTrashItem(
            $validated['site'],
            $validated['category'],
            $validated['uuid'],
            $request->user(),
        );

        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [
                    $field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages,
                ],
            )->all();

            return back()->withErrors($errors ?: ['force_delete' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: 'Élément supprimé définitivement.');
    }

    /**
     * ADR-236 — vider la corbeille : pour chaque site choisi, son API supprime définitivement
     * ce qui n'a servi nulle part selon les filtres, et garde le reste. Saisir « VIDER » est
     * exigé : c'est irréversible.
     */
    public function empty(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCodes = collect(config('rivo.clinics', []))->pluck('code')->all();
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'in:VIDER'],
            'site' => ['required', Rule::in(['ALL', ...$siteCodes])],
            'category' => ['nullable', Rule::in(['ALL', ...array_column(TrashCategory::options(), 'code')])],
            'search' => ['nullable', 'string', 'max:100'],
            'deleted_from' => ['nullable', 'date_format:Y-m-d'],
            'deleted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deleted_from'],
        ], ['confirmation.in' => 'Saisissez exactement VIDER pour confirmer.'], ['confirmation' => 'confirmation']);

        $filters = collect($data)->only(['category', 'search', 'deleted_from', 'deleted_to'])->filter(fn ($value) => filled($value))->all();
        $filters['category'] ??= 'ALL';
        $targets = $data['site'] === 'ALL' ? $siteCodes : [$data['site']];

        $report = ['action' => 'trash_empty', 'deleted' => 0, 'kept' => 0, 'sites' => []];
        foreach ($targets as $code) {
            $result = $client->emptyTrash($code, $filters, $request->user());
            $site = $result['site'] ?? ['code' => $code, 'name' => $code];
            if (! ($result['ok'] ?? false)) {
                $report['sites'][] = ['site' => $site, 'ok' => false, 'message' => $result['message'] ?? 'Site injoignable.'];

                continue;
            }

            $summary = $result['data'] ?? [];
            $report['deleted'] += (int) ($summary['deleted'] ?? 0);
            $report['kept'] += (int) ($summary['kept'] ?? 0);
            $report['sites'][] = ['site' => $site, 'ok' => true, ...$summary];
        }

        $failed = collect($report['sites'])->where('ok', false)->count();

        return back()
            ->with('status', $report['deleted'] === 0
                ? 'Rien n’a été supprimé : les éléments restants ont servi.'
                : "{$report['deleted']} élément".($report['deleted'] > 1 ? 's' : '').' supprimé'.($report['deleted'] > 1 ? 's' : '').' définitivement.'.($report['kept'] ? " {$report['kept']} conservé".($report['kept'] > 1 ? 's' : '').'.' : ''))
            ->with('status_type', $failed > 0 ? 'warning' : 'success')
            ->with('bulk_report', $report);
    }
}
