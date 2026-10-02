<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\Pharmacy\SupplierProductAiMatcher;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-241 — reconnaître le même produit sous deux noms, depuis le portail :
 * le dictionnaire des abréviations, les décisions « même produit / deux
 * produits », le rapprochement par l'IA et le prompt d'un catalogue. Tout
 * passe par l'API du site, qui revérifie et audite (ADR-004).
 */
class SupplierEquivalenceController extends Controller
{
    use RespondsToSiteApi;

    public function decide(Request $request, string $site, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $data = $request->validate([
            'item_uuid' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['SAME', 'DIFFERENT'])],
            'other_item_uuids' => ['nullable', 'array', 'max:50'],
            'other_item_uuids.*' => ['uuid'],
            'medicine_uuids' => ['nullable', 'array', 'max:10'],
            'medicine_uuids.*' => ['uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(
            $client->pharmacyCatalog($site, $request->user(), 'POST', 'product-equivalences', $data),
            $data['status'] === 'SAME' ? 'Mémorisé : c’est le même produit.' : 'Mémorisé : ce sont deux produits.',
        );
    }

    public function decisions(Request $request, string $site, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($client->pharmacyCatalog($site, $request->user(), 'GET', 'product-equivalences'));
    }

    public function forget(Request $request, string $site, string $equivalence, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($client->pharmacyCatalog($site, $request->user(), 'DELETE', 'product-equivalences/'.rawurlencode($equivalence)));
    }

    public function synonyms(Request $request, string $site, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($client->pharmacyCatalog($site, $request->user(), 'GET', 'product-synonyms'));
    }

    public function storeSynonym(Request $request, string $site, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);
        $data = $request->validate([
            'term' => ['required', 'string', 'max:40'],
            'canonical' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->json($client->pharmacyCatalog($site, $request->user(), 'POST', 'product-synonyms', $data));
    }

    public function destroySynonym(Request $request, string $site, string $synonym, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($client->pharmacyCatalog($site, $request->user(), 'DELETE', 'product-synonyms/'.rawurlencode($synonym)));
    }

    /**
     * « Rapprocher avec l'IA » : le portail relit le comparateur du site,
     * envoie au fournisseur d'IA les seuls libellés que la règle n'a pas su
     * rapprocher, et confie au site les paires proposées.
     */
    public function aiMatch(Request $request, string $site, PortalSiteApiClient $client, SupplierProductAiMatcher $matcher): RedirectResponse
    {
        $this->assertSite($site);
        $data = $request->validate([
            'suppliers' => ['nullable', 'array'],
            'suppliers.*' => ['uuid'],
            'family' => ['nullable', 'string', 'max:120'],
        ]);

        $comparison = $client->pharmacySupplierOffers($site, $request->user(), $data['suppliers'] ?? []);

        if (! $comparison['ok']) {
            return back()->withErrors(['ai' => $comparison['message'] ?: 'Le site est injoignable.']);
        }

        $lines = $matcher->candidates(data_get($comparison, 'data.medicines', []), $data['family'] ?? null);

        if ($lines === []) {
            return back()->with('status', 'Rien à envoyer à l’IA : toutes les lignes sont déjà rapprochées, ou un seul fournisseur les propose.');
        }

        $result = $matcher->match($request->user(), $lines);

        if (! $result['ok']) {
            return back()->withErrors(['ai' => $result['message']]);
        }

        if ($result['pairs'] === []) {
            return back()->with('status', $result['message']);
        }

        $stored = $client->pharmacyCatalog($site, $request->user(), 'POST', 'product-equivalences/proposals', ['pairs' => $result['pairs']]);

        return $stored['ok']
            ? back()->with('status', $stored['message'] ?: $result['message'])
            : back()->withErrors($this->siteErrors($stored));
    }

    /** ADR-241 — la structure d'un catalogue et son prompt, pour « Générer le prompt ». */
    public function catalogPrompt(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): JsonResponse
    {
        $this->assertSite($site);

        return $this->json($client->pharmacyCatalog(
            $site,
            $request->user(),
            'GET',
            'suppliers/'.rawurlencode($supplier).'/catalogs/'.rawurlencode($catalog).'/structure',
        ));
    }

    /** @param  array<string, mixed>  $result */
    private function json(array $result): JsonResponse
    {
        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] ?: 'Le site a refusé la demande.',
                'errors' => $result['errors'] ?? [],
            ], ($result['errors'] ?? []) !== [] ? 422 : 502);
        }

        return response()->json(['message' => $result['message'], 'data' => $result['data']]);
    }
}
