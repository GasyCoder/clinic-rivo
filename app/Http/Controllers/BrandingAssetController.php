<?php

namespace App\Http\Controllers;

use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le logo, l'icône et l'image de fond du site (ADR-184), servis sans
 * connexion : la page de connexion et le favicon en ont besoin avant toute
 * session. Rien d'autre ne
 * passe par ici — la signature du directeur n'a aucune adresse publique.
 *
 * L'adresse porte une version (`?v=`) qui change à chaque remplacement : le
 * fichier peut donc être gardé longtemps en cache sans jamais rester périmé.
 */
class BrandingAssetController extends Controller
{
    /** Ce qui peut s'afficher avant toute connexion. Jamais la signature. */
    public const PUBLIC_KINDS = ['logo', 'icon', 'background'];

    public function __invoke(string $kind, AppSettings $settings): StreamedResponse
    {
        abort_unless(in_array($kind, self::PUBLIC_KINDS, true) && $settings->assetExists($kind), 404);

        $path = $settings->assetPath($kind);

        return Storage::disk(AppSettings::DISK)->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
