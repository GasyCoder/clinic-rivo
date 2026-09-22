<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Rôles & permissions », séparé de « Utilisateurs » (ADR-100).
 *
 * Les deux vivaient sur un seul écran : on y créait un compte et on y
 * modifiait le socle d'un métier au même endroit, deux gestes de portée
 * très différente — l'un touche une personne, l'autre tous ceux qui
 * exercent ce métier. Cet écran porte le référentiel des rôles, leur socle,
 * et les exceptions individuelles accordées compte par compte.
 *
 * Le portail n'écrit jamais dans une base clinique : chaque commande part
 * vers l'API du site choisi, qui revérifie la permission et audite l'acteur
 * distant (ADR-004, ADR-027, ADR-064).
 */
class RoleController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        return Inertia::render('SuperAdmin/Roles/Index', [
            'sites' => $client->rolesForAllSites($request->user()),
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:100'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        return $this->respond(
            $client->createRole($validated['site_code'], collect($validated)->except('site_code')->all(), $request->user()),
            'Rôle créé.',
        );
    }

    public function update(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return $this->respond($client->updateRole($site, $role, $validated, $request->user()), 'Rôle mis à jour.');
    }

    public function archive(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond($client->archiveRole($site, $role, $validated['reason'], $request->user()), 'Rôle archivé.');
    }

    public function restore(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond($client->restoreRole($site, $role, $request->user()), 'Rôle restauré.');
    }

    public function updatePermissions(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
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

    public function resetPermissions(Request $request, string $site, string $role, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->resetRolePermissions($site, $role, $request->user()),
            'Le socle du rôle a été réinitialisé.',
        );
    }

    public function updateAccountPermissions(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'permission_overrides' => ['present', 'array'],
            'permission_overrides.*.permission_id' => ['required', 'integer'],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ]);

        return $this->respond(
            $client->updateUserPermissionOverrides($site, $user, $validated['permission_overrides'], $request->user()),
            'Permissions individuelles mises à jour.',
        );
    }

    public function resetAccountPermissions(Request $request, string $site, string $user, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->resetUserPermissions($site, $user, $request->user()),
            'Les permissions individuelles du compte ont été réinitialisées.',
        );
    }

    /**
     * Le catalogue des permissions d'un site (ADR-101). Une permission créée
     * ici est attribuable immédiatement, mais n'ouvre rien tant qu'aucune
     * route, Policy ou écran ne la vérifie — l'écran l'annonce.
     */
    public function storePermission(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'name' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        return $this->respond(
            $client->createPermission($validated['site_code'], collect($validated)->except('site_code')->all(), $request->user()),
            'Permission créée.',
        );
    }

    public function updatePermission(Request $request, string $site, int $permission, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:255']]);

        return $this->respond($client->updatePermission($site, $permission, $validated, $request->user()), 'Permission mise à jour.');
    }

    public function destroyPermission(Request $request, string $site, int $permission, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond($client->deletePermission($site, $permission, $request->user()), 'Permission retirée du catalogue.');
    }

    /** @return array<int, mixed> */
    private function siteCodeRules(): array
    {
        return ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())];
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
