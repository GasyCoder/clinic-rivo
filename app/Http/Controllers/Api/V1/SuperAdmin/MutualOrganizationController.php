<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MutualOrganization;
use App\Services\Administration\MutualOrganizationManager;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MutualOrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'mutual_organizations.view');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
        ]);
        $organizations = $this->query($validated)->get();

        return response()->json([
            'data' => $organizations->map(fn (MutualOrganization $organization) => $this->serialize($organization))->values(),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => $this->summary(),
            ],
        ]);
    }

    public function store(Request $request, MutualOrganizationManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'mutual_organizations.create');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'coverage_rate' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);
        $organization = $manager->create($validated['name'], $validated['coverage_rate'] ?? '100.00');

        return response()->json([
            'message' => 'Organisme ajouté au référentiel du site.',
            'data' => $this->serialize($organization),
        ], 201);
    }

    public function import(Request $request, MutualOrganizationManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'mutual_organizations.import');
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.coverage_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);

        $normalizedNames = collect($validated['rows'])
            ->map(fn (array $row) => MutualOrganization::normalize($row['name']));

        if ($normalizedNames->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'Le fichier contient plusieurs lignes pour le même organisme.',
            ]);
        }

        $result = DB::transaction(function () use ($validated, $manager): array {
            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($validated['rows'] as $row) {
                $organization = MutualOrganization::withTrashed()
                    ->where('normalized_name', MutualOrganization::normalize($row['name']))
                    ->lockForUpdate()
                    ->first();

                if ($organization?->trashed()) {
                    throw ValidationException::withMessages([
                        'rows' => "L’organisme {$row['name']} est archivé. Restaurez-le avant de l’importer.",
                    ]);
                }

                if (! $organization) {
                    $manager->create($row['name'], $row['coverage_rate']);
                    $created++;

                    continue;
                }

                if ($organization->name === str($row['name'])->squish()->toString()
                    && (string) $organization->coverage_rate === Money::normalize((string) $row['coverage_rate'])) {
                    $unchanged++;

                    continue;
                }

                $manager->update($organization, $row['name'], $row['coverage_rate']);
                $updated++;
            }

            return compact('created', 'updated', 'unchanged');
        });

        return response()->json([
            'message' => sprintf(
                'Import terminé : %d créé(s), %d mis à jour, %d inchangé(s).',
                $result['created'],
                $result['updated'],
                $result['unchanged'],
            ),
            'data' => $result,
        ]);
    }

    public function update(
        Request $request,
        string $organizationUuid,
        MutualOrganizationManager $manager,
    ): JsonResponse {
        $this->authorizeActor($request, 'mutual_organizations.update');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'coverage_rate' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);
        $organization = MutualOrganization::query()->where('uuid', $organizationUuid)->firstOrFail();
        $organization = $manager->update(
            $organization,
            $validated['name'],
            $validated['coverage_rate'] ?? $organization->coverage_rate,
        );

        return response()->json([
            'message' => 'Organisme mis à jour.',
            'data' => $this->serialize($organization),
        ]);
    }

    public function destroy(
        Request $request,
        string $organizationUuid,
        MutualOrganizationManager $manager,
    ): JsonResponse {
        $this->authorizeActor($request, 'mutual_organizations.archive');
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $organization = MutualOrganization::query()->where('uuid', $organizationUuid)->firstOrFail();
        $manager->archive($organization, $validated['reason']);

        return response()->json(['message' => 'Organisme archivé.']);
    }

    public function restore(
        Request $request,
        string $organizationUuid,
        MutualOrganizationManager $manager,
    ): JsonResponse {
        $this->authorizeActor($request, 'trash.restore');
        $this->authorizeActor($request, 'mutual_organizations.restore');
        $organization = MutualOrganization::onlyTrashed()->where('uuid', $organizationUuid)->firstOrFail();
        $organization = $manager->restore($organization);

        return response()->json([
            'message' => 'Organisme restauré.',
            'data' => $this->serialize($organization),
        ]);
    }

    public function bulkArchive(Request $request, MutualOrganizationManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'mutual_organizations.archive');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $count = DB::transaction(function () use ($validated, $manager): int {
            $organizations = MutualOrganization::query()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($organizations->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Un organisme sélectionné est absent ou déjà archivé. Aucune modification n’a été appliquée.',
                ]);
            }

            $organizations->each(
                fn (MutualOrganization $organization) => $manager->archive($organization, $validated['reason']),
            );

            return $organizations->count();
        });

        return response()->json([
            'message' => "{$count} organisme(s) archivé(s).",
            'data' => ['processed' => $count],
        ]);
    }

    public function bulkRestore(Request $request, MutualOrganizationManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'trash.restore');
        $this->authorizeActor($request, 'mutual_organizations.restore');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $count = DB::transaction(function () use ($validated, $manager): int {
            $organizations = MutualOrganization::onlyTrashed()
                ->whereIn('uuid', $validated['uuids'])
                ->lockForUpdate()
                ->get();

            if ($organizations->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Un organisme sélectionné est absent ou déjà actif. Aucune modification n’a été appliquée.',
                ]);
            }

            $organizations->each(fn (MutualOrganization $organization) => $manager->restore($organization));

            return $organizations->count();
        });

        return response()->json([
            'message' => "{$count} organisme(s) restauré(s).",
            'data' => ['processed' => $count],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters)
    {
        $status = $filters['status'] ?? 'ALL';
        $search = trim((string) ($filters['search'] ?? ''));

        return MutualOrganization::query()
            ->withCount([
                'coverages',
                'coverages as active_coverages_count' => fn ($query) => $query->active(),
            ])
            ->when($status === 'ARCHIVED', fn ($query) => $query->onlyTrashed())
            ->when($status === 'ALL', fn ($query) => $query->withTrashed())
            ->when($search !== '', fn ($query) => $query->where(
                'normalized_name',
                'like',
                '%'.MutualOrganization::normalize($search).'%',
            ))
            ->orderBy('normalized_name');
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        return [
            'active' => MutualOrganization::query()->count(),
            'archived' => MutualOrganization::onlyTrashed()->count(),
            'active_coverages' => MutualOrganization::query()
                ->withCount(['coverages as active_coverages_count' => fn ($query) => $query->active()])
                ->get()
                ->sum('active_coverages_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(MutualOrganization $organization): array
    {
        return [
            'uuid' => $organization->uuid,
            'name' => $organization->name,
            'coverage_rate' => $organization->coverage_rate,
            'patient_rate' => Money::fromMinor(10_000 - Money::toMinor($organization->coverage_rate)),
            'active' => ! $organization->trashed() && $organization->active,
            'coverages_count' => (int) ($organization->coverages_count ?? 0),
            'active_coverages_count' => (int) ($organization->active_coverages_count ?? 0),
            'archived_at' => $organization->deleted_at?->toIso8601String(),
            'archive_reason' => $organization->delete_reason,
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    private function authorizeActor(Request $request, string $permission): void
    {
        if (CatalogActor::fromRemoteRequest($request)->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
