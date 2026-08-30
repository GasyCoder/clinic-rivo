<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AddressEntry;
use App\Services\Administration\AddressEntryManager;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddressEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
        ]);
        $status = $validated['status'] ?? 'ACTIVE';
        $query = AddressEntry::query();

        if ($status !== 'ACTIVE') {
            $query->withTrashed();
        }

        if ($status === 'ARCHIVED') {
            $query->onlyTrashed();
        }

        if (filled($validated['search'] ?? null)) {
            $normalized = AddressEntry::normalize($validated['search']);
            $query->where('normalized_label', 'like', '%'.$normalized.'%');
        }

        $entries = $query->orderBy('normalized_label')->get();

        return response()->json([
            'data' => $entries->map(fn (AddressEntry $entry) => $this->serialize($entry))->values(),
            'meta' => [
                'site' => [
                    'code' => config('rivo.site.code'),
                    'name' => config('rivo.site.name'),
                ],
                'summary' => [
                    'displayed' => $entries->count(),
                    'active' => AddressEntry::query()->count(),
                    'archived' => AddressEntry::onlyTrashed()->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request, AddressEntryManager $manager): JsonResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $entry = $manager->create($validated['label']);

        return response()->json([
            'message' => 'Adresse ajoutée au référentiel du site.',
            'data' => $this->serialize($entry),
        ], 201);
    }

    public function import(Request $request, AddressEntryManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1', 'max:1000'],
            'labels.*' => ['required', 'string', 'max:255'],
        ]);
        $labels = collect($validated['labels'])
            ->map(fn (string $label) => str($label)->squish()->toString())
            ->unique(fn (string $label) => AddressEntry::normalize($label))
            ->values();

        $before = AddressEntry::query()->count();

        DB::transaction(function () use ($labels, $manager): void {
            $labels->each(fn (string $label) => $manager->create($label));
        });

        $created = AddressEntry::query()->count() - $before;

        return response()->json([
            'message' => sprintf(
                '%d adresse(s) importée(s), %d déjà présente(s).',
                $created,
                $labels->count() - $created,
            ),
            'data' => [
                'received' => count($validated['labels']),
                'unique' => $labels->count(),
                'created' => $created,
                'existing' => $labels->count() - $created,
            ],
        ]);
    }

    public function update(Request $request, string $addressUuid, AddressEntryManager $manager): JsonResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $entry = AddressEntry::query()->where('uuid', $addressUuid)->firstOrFail();
        $entry = $manager->update($entry, $validated['label']);

        return response()->json([
            'message' => 'Adresse mise à jour.',
            'data' => $this->serialize($entry),
        ]);
    }

    public function destroy(Request $request, string $addressUuid, AddressEntryManager $manager): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $entry = AddressEntry::query()->where('uuid', $addressUuid)->firstOrFail();
        $manager->archive($entry, $validated['reason']);

        return response()->json(['message' => 'Adresse archivée.']);
    }

    public function restore(Request $request, string $addressUuid, AddressEntryManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'trash.restore');
        $this->authorizeActor($request, 'address_entries.restore');
        $entry = AddressEntry::withTrashed()->where('uuid', $addressUuid)->firstOrFail();

        if ($entry->trashed()) {
            $entry = $manager->restore($entry);
        }

        return response()->json([
            'message' => 'Adresse restaurée.',
            'data' => $this->serialize($entry),
        ]);
    }

    public function bulkArchive(Request $request, AddressEntryManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'address_entries.archive');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $count = DB::transaction(function () use ($validated, $manager): int {
            $entries = AddressEntry::query()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($entries->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Une adresse sélectionnée est absente ou déjà archivée. Aucune modification n’a été appliquée.',
                ]);
            }

            $entries->each(fn (AddressEntry $entry) => $manager->archive($entry, $validated['reason']));

            return $entries->count();
        });

        return response()->json([
            'message' => "{$count} adresse(s) archivée(s).",
            'data' => ['processed' => $count],
        ]);
    }

    public function bulkRestore(Request $request, AddressEntryManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'trash.restore');
        $this->authorizeActor($request, 'address_entries.restore');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $count = DB::transaction(function () use ($validated, $manager): int {
            $entries = AddressEntry::onlyTrashed()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($entries->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Une adresse sélectionnée est absente ou déjà active. Aucune modification n’a été appliquée.',
                ]);
            }

            $entries->each(fn (AddressEntry $entry) => $manager->restore($entry));

            return $entries->count();
        });

        return response()->json([
            'message' => "{$count} adresse(s) restaurée(s).",
            'data' => ['processed' => $count],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(AddressEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'label' => $entry->label,
            'active' => ! $entry->trashed() && $entry->active,
            'archived_at' => $entry->deleted_at?->toIso8601String(),
            'archive_reason' => $entry->delete_reason,
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeActor(Request $request, string $permission): void
    {
        if (CatalogActor::fromRemoteRequest($request)->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
