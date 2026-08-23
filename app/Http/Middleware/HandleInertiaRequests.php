<?php

namespace App\Http\Middleware;

use App\Services\SuperAdmin\PortalDirectory;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ? [
                        'code' => $user->role->code,
                        'name' => $user->role->name,
                    ] : null,
                    'professional_profile' => $user->professionalProfile ? [
                        'code' => $user->professionalProfile->code,
                        'name' => $user->professionalProfile->name,
                    ] : null,
                ] : null,
            ],
            'permissions' => $user ? $user->effectivePermissionNames()->values()->all() : [],
            'adminNavigation' => fn () => $user
                && config('rivo.site.type') === 'admin'
                && $user->can('super_admin.portal.view')
                    ? app(PortalDirectory::class)->navigation()
                    : [],
            'site' => [
                'brand' => config('rivo.brand'),
                'code' => config('rivo.site.code'),
                'name' => config('rivo.site.name'),
                'type' => config('rivo.site.type'),
                'gatewayUrl' => config('rivo.gateway_url'),
                'publicUrl' => config('rivo.public_url'),
                'documents' => config('rivo.documents'),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                // Optional tone for the status toast (success/warning/danger/
                // info). Defaults to success client-side when absent, so the
                // dozens of existing ->with('status', ...) calls need no
                // change — only a message that isn't a plain success sets it.
                'status_type' => fn () => $request->session()->get('status_type'),
                'duplicates' => fn () => $request->session()->get('duplicates'),
            ],
        ];
    }
}
