<?php

namespace App\Http\Controllers;

use App\Enums\TrashCategory;
use App\Services\Catalog\CatalogActor;
use App\Services\Trash\TrashDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Local, single-site Corbeille — distinct from the Super Admin portal's
 * multi-site aggregator (Api/V1/SuperAdmin/TrashController, ADR-061), which
 * stays the only place that ever sees more than one site at once. This
 * reuses the same TrashDirectory service so the listed categories, the
 * restore business rules and the audit trail are exactly the ones already
 * built — only the actor (a local User instead of a remote CatalogActor)
 * and the scope (this site only) differ.
 */
class TrashController extends Controller
{
    public function index(Request $request, TrashDirectory $trash): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['ALL', ...array_column(TrashCategory::options(), 'code')])],
            'deleted_from' => ['nullable', 'date_format:Y-m-d'],
            'deleted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deleted_from'],
        ]);
        $validated['category'] ??= 'ALL';
        $result = $trash->list($validated);

        // TrashDirectory::serialize() always reports can_restore: true — it
        // is shared with the portal API, where the caller is always a
        // Super Admin. Here the viewer's role varies, so restore eligibility
        // is recomputed per record from the local user's real permissions
        // (ADR-061: restore permissions stay reserved to SUPER_ADMIN by
        // default, so most clinic accounts will only ever see a read-only
        // list — never a fabricated "true").
        $user = $request->user();
        $data = array_map(
            fn (array $record): array => [
                ...$record,
                'can_restore' => $user->can('trash.restore') && $user->can(TrashCategory::from($record['category'])->restorePermission()),
            ],
            $result['data'],
        );

        return Inertia::render('Trash/Index', [
            'records' => $data,
            'categories' => TrashCategory::options(),
            'summary' => $result['meta']['summary'],
            'filters' => $validated,
        ]);
    }

    public function restore(Request $request, string $category, string $uuid, TrashDirectory $trash): RedirectResponse
    {
        $validated = validator(
            ['category' => $category, 'uuid' => $uuid],
            ['category' => [Rule::enum(TrashCategory::class)], 'uuid' => ['required', 'uuid']],
        )->validate();

        $result = $trash->restore(
            TrashCategory::from($validated['category']),
            $validated['uuid'],
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', $result['already_restored'] ? 'Cet élément était déjà restauré.' : 'Élément restauré.');
    }
}
