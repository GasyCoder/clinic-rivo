<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\MutualOrganization;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MutualOrganizationController extends Controller
{
    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'coverage_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);
        $this->assertSite($validated['site_code']);

        return $this->respond(
            $client->createMutualOrganization(
                $validated['site_code'],
                $validated['name'],
                $validated['coverage_rate'] ?? '100.00',
                $request->user(),
            ),
            'Organisme créé.',
        );
    }

    public function update(
        Request $request,
        string $site,
        string $organization,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertSite($site);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'coverage_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
        ]);

        return $this->respond(
            $client->updateMutualOrganization(
                $site,
                $organization,
                $validated['name'],
                $validated['coverage_rate'] ?? '100.00',
                $request->user(),
            ),
            'Organisme mis à jour.',
        );
    }

    public function export(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): StreamedResponse {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in(['ALL', ...$this->siteCodes()])],
            'uuids' => ['nullable', 'array', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $sites = collect($client->catalogForAllSites($request->user(), ['status' => 'ALL']))
            ->when($validated['site_code'] !== 'ALL', fn ($items) => $items->where('site.code', $validated['site_code']))
            ->where('ok', true)
            ->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne fournit actuellement ses organismes mutuels.');

        $selectedUuids = collect($validated['uuids'] ?? []);
        $organizations = $sites->flatMap(fn (array $site) => collect(data_get($site, 'data.mutual_organizations', []))
            ->when($selectedUuids->isNotEmpty(), fn ($items) => $items->whereIn('uuid', $selectedUuids))
            ->map(fn (array $organization) => ['site' => $site, 'organization' => $organization]));

        if ($selectedUuids->isNotEmpty() && $organizations->count() !== $selectedUuids->count()) {
            throw ValidationException::withMessages([
                'uuids' => 'Un organisme sélectionné est absent des données actuelles. Aucun export n’a été généré.',
            ]);
        }

        $rows = $organizations->map(fn (array $selected) => [
            data_get($selected, 'site.site.code'),
            data_get($selected, 'site.site.name'),
            data_get($selected, 'organization.uuid'),
            data_get($selected, 'organization.name'),
            data_get($selected, 'organization.coverage_rate'),
            data_get($selected, 'organization.patient_rate'),
            data_get($selected, 'organization.active_coverages_count', 0),
            data_get($selected, 'organization.active') ? 'ACTIVE' : 'ARCHIVED',
        ]);
        $scope = $selectedUuids->isNotEmpty()
            ? 'selection-'.$selectedUuids->count()
            : ($validated['site_code'] === 'ALL' ? 'tous-les-sites' : mb_strtolower($validated['site_code']));

        return $excel->download(
            'mutuelles-'.$scope.'-'.now()->format('Y-m-d-His'),
            'Mutuelles et couvertures',
            [
                'Code site', 'Site', 'UUID', 'Organisme', 'Couverture mutuelle (%)',
                'Part patient (%)', 'Adhésions actives', 'Statut',
            ],
            $rows,
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-mutuelles',
            'Mutuelles à importer',
            ['Organisme', 'Couverture mutuelle (%)'],
            [
                ['Exemple couverture totale', 100],
                ['Exemple couverture partielle', 80],
            ],
        );
    }

    public function import(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): RedirectResponse {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        $rows = $this->organizationImportRows($excel->rows($validated['file']));

        return $this->respond(
            $client->importMutualOrganizations($validated['site_code'], $rows, $request->user()),
            count($rows).' organisme(s) traité(s).',
        );
    }

    public function destroy(
        Request $request,
        string $site,
        string $organization,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond(
            $client->archiveMutualOrganization($site, $organization, $validated['reason'], $request->user()),
            'Organisme archivé.',
        );
    }

    public function restore(
        Request $request,
        string $site,
        string $organization,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertSite($site);

        return $this->respond(
            $client->restoreMutualOrganization($site, $organization, $request->user()),
            'Organisme restauré.',
        );
    }

    public function bulkArchive(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string'],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $this->assertSite($validated['site_code']);

        return $this->respond(
            $client->bulkArchiveMutualOrganizations(
                $validated['site_code'],
                $validated['uuids'],
                $validated['reason'],
                $request->user(),
            ),
            count($validated['uuids']).' organisme(s) archivé(s).',
        );
    }

    public function bulkRestore(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string'],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $this->assertSite($validated['site_code']);

        return $this->respond(
            $client->bulkRestoreMutualOrganizations(
                $validated['site_code'],
                $validated['uuids'],
                $request->user(),
            ),
            count($validated['uuids']).' organisme(s) restauré(s).',
        );
    }

    private function assertSite(string $site): void
    {
        abort_unless(
            collect(config('rivo.clinics', []))->contains('code', mb_strtoupper($site)),
            404,
        );
    }

    /** @return array<int, string> */
    private function siteCodes(): array
    {
        return collect(config('rivo.clinics', []))->pluck('code')->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawRows
     * @return array<int, array{name: string, coverage_rate: string}>
     */
    private function organizationImportRows(array $rawRows): array
    {
        $rows = collect();

        foreach ($rawRows as $index => $row) {
            $name = str((string) ($row['organisme'] ?? $row['name'] ?? ''))->squish()->toString();

            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Le nom de l’organisme est invalide à la ligne %d.', $index + 2),
                ]);
            }

            $rawRate = str_replace(',', '.', trim((string) ($row['couverture_mutuelle'] ?? $row['coverage_rate'] ?? '100')));

            try {
                $rate = Money::normalize($rawRate);
            } catch (\InvalidArgumentException|\OverflowException) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Le taux de couverture est invalide à la ligne %d.', $index + 2),
                ]);
            }

            if (Money::toMinor($rate) < 0 || Money::toMinor($rate) > 10_000) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Le taux de couverture doit être compris entre 0 et 100 %% à la ligne %d.', $index + 2),
                ]);
            }

            $rows->push(['name' => $name, 'coverage_rate' => $rate]);

            if ($rows->count() > 1000) {
                throw ValidationException::withMessages(['file' => 'Un import est limité à 1 000 organismes.']);
            }
        }

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucun organisme à importer.']);
        }

        $duplicates = $rows->map(fn (array $row) => MutualOrganization::normalize($row['name']))
            ->duplicates();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Le fichier contient plusieurs lignes pour le même organisme.']);
        }

        return $rows->values()->all();
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result, string $successMessage): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [
                    $field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages,
                ],
            )->all();

            return back()->withErrors($errors ?: ['organization' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: $successMessage);
    }
}
