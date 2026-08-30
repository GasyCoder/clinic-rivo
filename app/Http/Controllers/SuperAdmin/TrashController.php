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
}
