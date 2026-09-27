<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdmin\SiteHrGateway;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-187 — l'espace RH d'un site, géré depuis le portail (CDC §2, §18).
 */
class SiteHumanResourcesController extends SiteScreenController
{
    public function __invoke(Request $request, string $site, SiteHrGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        // Au portail, les adresses professionnelles se gèrent sur sa propre page :
        // c'est lui qui détient l'accès à l'hébergeur (ADR-190). La page relayée
        // du site y renverrait (« la création se fait depuis le portail »).
        if ($request->isMethod('GET') && trim($path, '/') === 'professional-emails') {
            return redirect()->route('super-admin.professional-emails.index', ['site' => mb_strtoupper($site)]);
        }

        return $this->relay($request, $site, $gateway, $path);
    }

    protected function contextKey(): string
    {
        return 'hrContext';
    }

    protected function overviewRoute(): string
    {
        return 'super-admin.workspaces.hr';
    }
}
