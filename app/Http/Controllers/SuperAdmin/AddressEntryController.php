<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AddressEntryController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
        ]);
        $filters['status'] ??= 'ACTIVE';

        return Inertia::render('SuperAdmin/Addresses/Index', [
            'sites' => $client->addressesForAllSites($request->user(), array_filter($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'label' => ['required', 'string', 'max:255'],
        ]);

        return $this->respond(
            $client->createAddress($validated['site_code'], $validated['label'], $request->user()),
            'Adresse ajoutée au référentiel du site.',
        );
    }

    public function export(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel): StreamedResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in([
                'ALL',
                ...collect(config('rivo.clinics', []))->pluck('code')->all(),
            ])],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])],
            'uuids' => ['nullable', 'array', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $filters = collect($validated)->only(['search', 'status'])->filter()->all();
        $sites = collect($client->addressesForAllSites($request->user(), $filters))
            ->when($validated['site_code'] !== 'ALL', fn ($items) => $items
                ->where('site.code', $validated['site_code']))
            ->where('ok', true)
            ->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne fournit actuellement son référentiel d’adresses.');

        $selectedUuids = collect($validated['uuids'] ?? []);
        $scope = $selectedUuids->isNotEmpty()
            ? 'selection-'.$selectedUuids->count()
            : ($validated['site_code'] === 'ALL' ? 'tous-les-sites' : mb_strtolower($validated['site_code']));

        $selectedEntries = $sites->flatMap(fn (array $site) => collect($site['data'] ?? [])
            ->when($selectedUuids->isNotEmpty(), fn ($entries) => $entries->whereIn('uuid', $selectedUuids))
            ->map(fn (array $entry) => ['site' => $site, 'entry' => $entry]));

        if ($selectedUuids->isNotEmpty() && $selectedEntries->count() !== $selectedUuids->count()) {
            throw ValidationException::withMessages([
                'uuids' => 'Une adresse sélectionnée est absente du site ou des filtres actuels. Aucun export n’a été généré.',
            ]);
        }

        $rows = $selectedEntries->map(fn (array $selected) => [
            data_get($selected, 'site.site.code'),
            data_get($selected, 'site.site.name'),
            data_get($selected, 'entry.uuid'),
            data_get($selected, 'entry.label'),
            data_get($selected, 'entry.active') ? 'ACTIVE' : 'ARCHIVED',
            data_get($selected, 'entry.archived_at'),
            data_get($selected, 'entry.archive_reason'),
            data_get($selected, 'entry.updated_at'),
        ]);

        return $excel->download(
            'adresses-'.$scope.'-'.now()->format('Y-m-d-His'),
            'Référentiel adresses',
            ['Code site', 'Site', 'UUID', 'Adresse', 'Statut', 'Archivée le', 'Motif archivage', 'Mise à jour le'],
            $rows,
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-adresses',
            'Adresses à importer',
            ['Adresse'],
            [['Ambondromamy centre'], ['Quartier Nord']],
        );
    }

    public function import(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        $labels = $this->readAddressLabels($excel->rows($validated['file']));

        return $this->respond(
            $client->importAddresses($validated['site_code'], $labels, $request->user()),
            sprintf('%d adresse(s) traitée(s).', count($labels)),
        );
    }

    public function update(Request $request, string $site, string $address, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:255']]);

        return $this->respond(
            $client->updateAddress($site, $address, $validated['label'], $request->user()),
            'Adresse mise à jour.',
        );
    }

    public function destroy(Request $request, string $site, string $address, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond(
            $client->archiveAddress($site, $address, $validated['reason'], $request->user()),
            'Adresse archivée.',
        );
    }

    public function restore(Request $request, string $site, string $address, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->restoreAddress($site, $address, $request->user()),
            'Adresse restaurée.',
        );
    }

    public function bulkArchive(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->respond(
            $client->bulkArchiveAddresses(
                $validated['site_code'],
                $validated['uuids'],
                $validated['reason'],
                $request->user(),
            ),
            count($validated['uuids']).' adresse(s) archivée(s).',
        );
    }

    public function bulkRestore(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return $this->respond(
            $client->bulkRestoreAddresses($validated['site_code'], $validated['uuids'], $request->user()),
            count($validated['uuids']).' adresse(s) restaurée(s).',
        );
    }

    /** @return array<int, mixed> */
    private function siteCodeRules(): array
    {
        return ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function readAddressLabels(array $rows): array
    {
        $labels = collect();

        foreach ($rows as $index => $row) {
            $label = trim((string) ($row['adresse'] ?? $row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > 255) {
                throw ValidationException::withMessages([
                    'file' => sprintf('L’adresse de la ligne %d dépasse 255 caractères.', $index + 2),
                ]);
            }

            $labels->push(str($label)->squish()->toString());

            if ($labels->count() > 1000) {
                throw ValidationException::withMessages([
                    'file' => 'Un import est limité à 1 000 adresses.',
                ]);
            }
        }

        $labels = $labels->unique(fn (string $label) => Str::lower(Str::ascii($label)))->values();

        if ($labels->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier Excel ne contient aucune adresse. Utilisez la colonne « Adresse » du modèle.',
            ]);
        }

        return $labels->all();
    }

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
