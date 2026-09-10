<?php

namespace App\Services\SuperAdmin;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PortalSiteApiClient
{
    /** @return array<int, array<string, mixed>> */
    public function trashForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/trash', $query, $actor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function restoreTrashItem(
        string $siteCode,
        string $category,
        string $uuid,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/trash/'.$category.'/'.$uuid.'/restore',
            [],
            $actor,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function stockForAllSites(User $actor): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/pharmacy/stock', [], $actor))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function humanResourcesForAllSites(User $actor): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/human-resources', [], $actor))
            ->values()
            ->all();
    }

    /** @param array<int, array<string, int|string|null>> $rows */
    public function importStock(string $siteCode, array $rows, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/pharmacy/stock/import',
            ['rows' => $rows],
            $actor,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function addressesForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/address-entries', $query, $actor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function createAddress(string $siteCode, string $label, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/address-entries', ['label' => $label], $actor);
    }

    /** @return array<string, mixed> */
    public function updateAddress(string $siteCode, string $uuid, string $label, User $actor): array
    {
        return $this->request($this->site($siteCode), 'PUT', 'super-admin/address-entries/'.$uuid, ['label' => $label], $actor);
    }

    /** @return array<string, mixed> */
    public function archiveAddress(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request($this->site($siteCode), 'DELETE', 'super-admin/address-entries/'.$uuid, ['reason' => $reason], $actor);
    }

    /** @return array<string, mixed> */
    public function restoreAddress(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/address-entries/'.$uuid.'/restore', [], $actor);
    }

    /** @param array<int, string> $uuids */
    public function bulkArchiveAddresses(string $siteCode, array $uuids, string $reason, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/address-entries/bulk/archive',
            ['uuids' => $uuids, 'reason' => $reason],
            $actor,
        );
    }

    /** @param array<int, string> $uuids */
    public function bulkRestoreAddresses(string $siteCode, array $uuids, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/address-entries/bulk/restore',
            ['uuids' => $uuids],
            $actor,
        );
    }

    /** @param array<int, string> $labels */
    public function importAddresses(string $siteCode, array $labels, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/address-entries/import',
            ['labels' => $labels],
            $actor,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function cashRegistersForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/cash-registers', $query, $actor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function createCashRegister(string $siteCode, string $name, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/cash-registers', ['name' => $name], $actor);
    }

    /** @return array<string, mixed> */
    public function cashRegisterProfile(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'GET', 'super-admin/cash-registers/'.$uuid, [], $actor);
    }

    /** @return array<string, mixed> */
    public function updateCashRegister(string $siteCode, string $uuid, string $name, User $actor): array
    {
        return $this->request($this->site($siteCode), 'PUT', 'super-admin/cash-registers/'.$uuid, ['name' => $name], $actor);
    }

    /** @return array<string, mixed> */
    public function activateCashRegister(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/cash-registers/'.$uuid.'/activate', [], $actor);
    }

    /**
     * @param  array<int, string>  $paymentMethodUuids
     * @return array<string, mixed>
     */
    public function updateCashRegisterPaymentMethods(
        string $siteCode,
        string $uuid,
        array $paymentMethodUuids,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'PUT',
            'super-admin/cash-registers/'.$uuid.'/payment-methods',
            ['payment_method_uuids' => $paymentMethodUuids],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function deactivateCashRegister(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/cash-registers/'.$uuid.'/deactivate', [], $actor);
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentMethodsForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/payment-methods', $query, $actor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function createPaymentMethod(string $siteCode, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/payment-methods', $data, $actor);
    }

    /** @return array<string, mixed> */
    public function updatePaymentMethod(string $siteCode, string $uuid, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'PUT', 'super-admin/payment-methods/'.$uuid, $data, $actor);
    }

    /** @return array<string, mixed> */
    public function activatePaymentMethod(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/payment-methods/'.$uuid.'/activate', [], $actor);
    }

    /** @return array<string, mixed> */
    public function deactivatePaymentMethod(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/payment-methods/'.$uuid.'/deactivate', [], $actor);
    }

    /** @return array<int, array<string, mixed>> */
    public function usersForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/users', $query, $actor))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function createUser(string $siteCode, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/users', $data, $actor);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function updateUser(string $siteCode, string $uuid, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'PUT', 'super-admin/users/'.$uuid, $data, $actor);
    }

    /** @param array<int, int> $permissionIds
     * @return array<string, mixed> */
    public function updateRolePermissions(string $siteCode, string $roleCode, array $permissionIds, User $actor): array
    {
        return $this->request(
            $this->site($siteCode), 'PUT', 'super-admin/roles/'.$roleCode.'/permissions',
            ['permission_ids' => $permissionIds], $actor,
        );
    }

    /** @return array<string, mixed> */
    public function activateUser(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/users/'.$uuid.'/activate', [], $actor);
    }

    /** @return array<string, mixed> */
    public function deactivateUser(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/users/'.$uuid.'/deactivate', ['reason' => $reason], $actor);
    }

    /** @param array<int, string> $uuids
     * @return array<string, mixed> */
    public function bulkDeactivateUsers(string $siteCode, array $uuids, string $reason, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/users/bulk/deactivate', ['uuids' => $uuids, 'reason' => $reason], $actor);
    }

    /** @return array<string, mixed> */
    public function forceDeleteUser(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'DELETE', 'super-admin/users/'.$uuid, [], $actor);
    }

    /** @param array<int, string> $uuids
     * @return array<string, mixed> */
    public function bulkForceDeleteUsers(string $siteCode, array $uuids, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/users/bulk/force-delete', ['uuids' => $uuids], $actor);
    }

    /** @return array<string, mixed> */
    public function archiveCashRegister(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request($this->site($siteCode), 'DELETE', 'super-admin/cash-registers/'.$uuid, ['reason' => $reason], $actor);
    }

    /** @return array<string, mixed> */
    public function restoreCashRegister(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/cash-registers/'.$uuid.'/restore', [], $actor);
    }

    /** @return array<string, mixed> */
    public function lockCashRegisterSession(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/cash-registers/'.$uuid.'/session/lock',
            ['reason' => $reason],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function unlockCashRegisterSession(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/cash-registers/'.$uuid.'/session/unlock',
            ['reason' => $reason],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function closeCashRegisterSession(
        string $siteCode,
        string $uuid,
        string $actualClosingAmount,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/cash-registers/'.$uuid.'/session/close',
            ['actual_closing_amount' => $actualClosingAmount, 'reason' => $reason],
            $actor,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function catalogForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/catalog', $query, $actor))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function createCatalogItem(string $siteCode, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/catalog', $data, $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateCatalogItem(string $siteCode, string $uuid, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'PUT', 'super-admin/catalog/'.$uuid, $data, $actor);
    }

    public function archiveCatalogItem(string $siteCode, string $uuid, string $reason, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'DELETE',
            'super-admin/catalog/'.$uuid,
            ['reason' => $reason],
            $actor,
        );
    }

    public function restoreCatalogItem(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/'.$uuid.'/restore',
            [],
            $actor,
        );
    }

    /** @param array<int, string> $uuids */
    public function bulkArchiveCatalogItems(
        string $siteCode,
        array $uuids,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/bulk/archive',
            ['uuids' => $uuids, 'reason' => $reason],
            $actor,
        );
    }

    /** @param array<int, string> $uuids */
    public function bulkRestoreCatalogItems(string $siteCode, array $uuids, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/bulk/restore',
            ['uuids' => $uuids],
            $actor,
        );
    }

    public function setCatalogTariff(
        string $siteCode,
        string $uuid,
        string $category,
        string|int $amount,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/'.$uuid.'/tariffs',
            [
                'tariff_category' => $category,
                'tariff_amount' => $amount,
                'reason' => $reason,
            ],
            $actor,
        );
    }

    public function archiveCatalogTariff(
        string $siteCode,
        string $uuid,
        string $category,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/'.$uuid.'/tariffs/archive',
            ['tariff_category' => $category, 'reason' => $reason],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function createMutualOrganization(
        string $siteCode,
        string $name,
        string|int $coverageRate,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/mutual-organizations',
            ['name' => $name, 'coverage_rate' => $coverageRate],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function updateMutualOrganization(
        string $siteCode,
        string $uuid,
        string $name,
        string|int $coverageRate,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'PUT',
            'super-admin/mutual-organizations/'.$uuid,
            ['name' => $name, 'coverage_rate' => $coverageRate],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function archiveMutualOrganization(
        string $siteCode,
        string $uuid,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'DELETE',
            'super-admin/mutual-organizations/'.$uuid,
            ['reason' => $reason],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    public function restoreMutualOrganization(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/mutual-organizations/'.$uuid.'/restore',
            [],
            $actor,
        );
    }

    /** @param array<int, string> $uuids */
    public function bulkArchiveMutualOrganizations(
        string $siteCode,
        array $uuids,
        string $reason,
        User $actor,
    ): array {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/mutual-organizations/bulk/archive',
            ['uuids' => $uuids, 'reason' => $reason],
            $actor,
        );
    }

    /** @param array<int, string> $uuids */
    public function bulkRestoreMutualOrganizations(string $siteCode, array $uuids, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/mutual-organizations/bulk/restore',
            ['uuids' => $uuids],
            $actor,
        );
    }

    /** @param array<int, array{name: string, coverage_rate: string}> $rows */
    public function importMutualOrganizations(string $siteCode, array $rows, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/mutual-organizations/import',
            ['rows' => $rows],
            $actor,
        );
    }

    /** @param array<int, array{code: string, standard_amount?: string, mutual_amount?: string, reason: string}> $rows */
    public function importCatalogTariffs(string $siteCode, array $rows, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/catalog/tariffs/import',
            ['rows' => $rows],
            $actor,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function analysisCatalogsForAllSites(User $actor, array $query = []): array
    {
        return collect(config('rivo.clinics', []))
            ->map(fn (array $site) => $this->request($site, 'GET', 'super-admin/analysis-catalogs', $query, $actor))
            ->values()
            ->all();
    }

    public function analysisDetail(string $siteCode, string $uuid, User $actor): array
    {
        return $this->request($this->site($siteCode), 'GET', 'super-admin/analysis-catalogs/'.$uuid, [], $actor);
    }

    /** @param array<string, mixed> $data */
    public function createAnalysis(string $siteCode, array $data, User $actor): array
    {
        return $this->request($this->site($siteCode), 'POST', 'super-admin/analysis-catalogs', $data, $actor);
    }

    /** @param array<string, mixed> $data */
    public function updateAnalysis(string $siteCode, string $uuid, array $data, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'PUT',
            'super-admin/analysis-catalogs/'.$uuid,
            $data,
            $actor,
        );
    }

    public function setAnalysisActive(string $siteCode, string $uuid, bool $active, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/analysis-catalogs/'.$uuid.'/'.($active ? 'activate' : 'deactivate'),
            [],
            $actor,
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function importAnalyses(string $siteCode, array $rows, User $actor): array
    {
        return $this->request(
            $this->site($siteCode),
            'POST',
            'super-admin/analysis-catalogs/import',
            ['rows' => $rows],
            $actor,
        );
    }

    /** @return array<string, mixed> */
    private function request(array $site, string $method, string $path, array $payload, User $actor): array
    {
        $identity = ['code' => $site['code'], 'name' => $site['name']];
        $apiUrl = trim((string) ($site['api_url'] ?? ''));
        $token = trim((string) ($site['api_token'] ?? ''));

        if ($apiUrl === '' || $token === '') {
            return [
                'site' => $identity,
                'status' => 'UNCONFIGURED',
                'ok' => false,
                'message' => 'L’URL ou le jeton API de ce site n’est pas configuré.',
                'data' => null,
                'meta' => null,
                'errors' => [],
            ];
        }

        try {
            $requestUuid = (string) Str::uuid();
            $pending = Http::acceptJson()
                ->withToken($token)
                ->withHeaders([
                    'X-Request-UUID' => $requestUuid,
                    'X-Rivo-Actor-UUID' => $actor->uuid,
                    'X-Rivo-Actor-Name' => $actor->name,
                    'X-Rivo-Actor-Permissions' => $actor->effectivePermissionNames()->implode(','),
                ])
                ->timeout(max(1, (int) config('rivo.site_api.timeout', 5)))
                ->retry(max(1, (int) config('rivo.site_api.retry_times', 2)), 150, throw: false);

            if ($method !== 'GET') {
                $pending = $pending->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
            }

            $url = rtrim($apiUrl, '/').'/'.ltrim($path, '/');
            $response = $method === 'GET'
                ? $pending->get($url, $payload)
                : $pending->send($method, $url, ['json' => $payload]);

            return $this->normalizeResponse($identity, $response);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'site' => $identity,
                'status' => 'OFFLINE',
                'ok' => false,
                'message' => 'Le site ne répond pas actuellement. Les autres sites restent disponibles.',
                'data' => null,
                'meta' => null,
                'errors' => [],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function normalizeResponse(array $site, Response $response): array
    {
        $json = $response->json();

        return [
            'site' => $site,
            'status' => $response->successful() ? 'ONLINE' : 'ERROR',
            'ok' => $response->successful(),
            'message' => $response->successful()
                ? ($json['message'] ?? null)
                : ($json['message'] ?? 'L’API du site a refusé la requête.'),
            'data' => $json['data'] ?? null,
            'meta' => $json['meta'] ?? null,
            'errors' => $json['errors'] ?? [],
            'http_status' => $response->status(),
        ];
    }

    /** @return array<string, mixed> */
    private function site(string $code): array
    {
        $site = collect(config('rivo.clinics', []))
            ->firstWhere('code', mb_strtoupper($code));

        abort_unless($site, 404);

        return $site;
    }
}
