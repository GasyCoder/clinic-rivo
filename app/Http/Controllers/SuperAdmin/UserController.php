<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'role' => ['nullable', 'string', 'max:50'],
        ]);
        $filters['status'] ??= 'active';

        return Inertia::render('SuperAdmin/Users/Index', [
            'sites' => $client->usersForAllSites($request->user(), array_filter($filters)),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            ...$this->userRules(),
        ]);

        return $this->respond(
            $client->createUser($validated['site_code'], collect($validated)->except('site_code')->all(), $request->user()),
            'Compte créé.',
        );
    }

    public function update(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate($this->userRules());

        return $this->respond(
            $client->updateUser($site, $user, $validated, $request->user()),
            'Compte mis à jour.',
        );
    }

    public function activate(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->activateUser($site, $user, $request->user()),
            'Compte réactivé.',
        );
    }

    public function deactivate(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->respond(
            $client->deactivateUser($site, $user, $validated['reason'], $request->user()),
            'Compte désactivé.',
        );
    }

    public function bulkDeactivate(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return $this->respond(
            $client->bulkDeactivateUsers($validated['site_code'], $validated['uuids'], $validated['reason'], $request->user()),
            count($validated['uuids']).' compte(s) désactivé(s).',
        );
    }

    public function forceDelete(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->forceDeleteUser($site, $user, $request->user()),
            'Compte supprimé définitivement.',
        );
    }

    public function bulkForceDelete(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return $this->respond(
            $client->bulkForceDeleteUsers($validated['site_code'], $validated['uuids'], $request->user()),
            count($validated['uuids']).' compte(s) supprimé(s) définitivement.',
        );
    }

    public function updateRolePermissions(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        return $this->respond(
            $client->updateRolePermissions($site, $role, $validated['permission_ids'], $request->user()),
            'Permissions du rôle mises à jour.',
        );
    }

    /** @return array<int, mixed> */
    private function siteCodeRules(): array
    {
        return ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())];
    }

    /**
     * role_id/professional_profile_id/permission_overrides.*.permission_id
     * reference rows on the REMOTE site's own database, never the portal's —
     * only presence/shape is checked here, the site API re-validates and
     * owns the authoritative existence checks. password is always optional:
     * omitted on creation, the account is provisioned by email invitation
     * instead (CreateUserAction sends the reset-password link).
     *
     * @return array<string, mixed>
     */
    private function userRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
            'role_id' => ['required', 'integer'],
            'professional_profile_id' => ['nullable', 'integer'],
            'permission_overrides' => ['nullable', 'array'],
            'permission_overrides.*.permission_id' => ['required_with:permission_overrides', 'integer'],
            'permission_overrides.*.effect' => ['required_with:permission_overrides', Rule::in(['allow', 'deny'])],
        ];
    }

    /** @param array<string, mixed> $result */
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
