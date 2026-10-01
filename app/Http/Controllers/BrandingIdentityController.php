<?php

namespace App\Http\Controllers;

use App\Services\Settings\AppSettings;
use App\Services\Settings\SiteMaintenanceState;
use Illuminate\Http\JsonResponse;

/**
 * ADR-184 (amendement du 2026-10-02) — l'identité publique d'un déploiement :
 * son enseigne, son logo et son icône, déjà visibles sur sa page de connexion.
 *
 * La passerelle (rivo.mg) la lit par HTTP pour afficher le logo de chaque site
 * et le logo central du portail, sans jamais toucher à une base (ADR-004).
 * Rien d'autre n'y figure : ni signature, ni coordonnées, ni donnée métier.
 */
class BrandingIdentityController extends Controller
{
    public function __invoke(AppSettings $settings, SiteMaintenanceState $maintenance): JsonResponse
    {
        $custom = $settings->assetExists('logo');

        return response()->json([
            'brand' => $settings->brand(),
            'tagline' => $settings->tagline(),
            'logo_url' => $this->absolute($settings->logoUrl()),
            'custom_logo' => $custom,
            'icon_url' => $this->absolute($settings->iconUrl()),
            'maintenance' => $maintenance->isActive(),
        ], 200, [
            // Un logo remplacé se voit en quelques minutes ; l'image elle-même
            // porte sa version et reste en cache un an.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** Une adresse que la passerelle peut charger depuis un autre domaine. */
    private function absolute(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }
}
