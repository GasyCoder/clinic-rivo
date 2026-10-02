<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\Pharmacy\SupplierProductAiMatcher;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
     * ADR-242 — « Rapprocher avec l'IA », étape 1 : le portail relit le
     * comparateur du site, garde les seuls libellés que la règle n'a pas su
     * rapprocher et les découpe en lots. Rien ne part encore à l'IA : l'écran
     * envoie ensuite les lots un par un et montre où il en est.
     */
    public function aiPlan(Request $request, string $site, PortalSiteApiClient $client, SupplierProductAiMatcher $matcher): JsonResponse
    {
        $this->assertSite($site);
        $data = $request->validate([
            'suppliers' => ['nullable', 'array'],
            'suppliers.*' => ['uuid'],
            'family' => ['nullable', 'string', 'max:120'],
        ]);

        if ($refusal = $matcher->unavailable($request->user())) {
            return response()->json(['message' => $refusal], 422);
        }

        $comparison = $client->pharmacySupplierOffers($site, $request->user(), $data['suppliers'] ?? []);

        if (! $comparison['ok']) {
            return response()->json(['message' => $comparison['message'] ?: 'Le site est injoignable.'], 502);
        }

        $lines = $matcher->candidates(data_get($comparison, 'data.medicines', []), $data['family'] ?? null);
        $plan = SupplierProductAiMatcher::plan($lines);

        if ($plan['batches'] === []) {
            return response()->json([
                'data' => ['run' => null, 'batches' => []],
                'message' => 'Rien à envoyer à l’IA : toutes les lignes sont déjà rapprochées, ou aucune n’a de voisine chez un autre fournisseur.',
            ]);
        }

        $run = (string) Str::uuid();
        Cache::put(self::runKey($run), [
            'user' => $request->user()->getKey(),
            'site' => $site,
            'batches' => $plan['batches'],
        ], now()->addHours(2));

        return response()->json(['data' => [
            'run' => $run,
            'candidates' => count($lines),
            'remaining' => $plan['remaining'],
            'alone' => $plan['alone'],
            'batches' => collect($plan['batches'])->map(fn (array $batch, int $index) => [
                'index' => $index,
                'lines' => count($batch),
                'preview' => collect($batch)->take(2)->pluck('label')->all(),
            ])->all(),
        ], 'message' => count($plan['batches']).' lot(s) à envoyer à l’IA.']);
    }

    /**
     * ADR-242 — étape 2 : un lot du plan part à l'IA, et ses paires sont
     * confiées au site comme propositions. Un lot qui échoue se relance seul.
     */
    public function aiBatch(Request $request, string $site, string $run, int $batch, PortalSiteApiClient $client, SupplierProductAiMatcher $matcher): JsonResponse
    {
        $this->assertSite($site);
        $plan = Cache::get(self::runKey($run));

        if (! is_array($plan) || $plan['site'] !== $site || $plan['user'] !== $request->user()->getKey() || ! isset($plan['batches'][$batch])) {
            return response()->json(['message' => 'Ce rapprochement a expiré : relancez « Rapprocher avec l’IA ».'], 404);
        }

        $result = $matcher->match($request->user(), $plan['batches'][$batch]);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        if ($result['pairs'] === []) {
            return response()->json(['data' => ['proposed' => 0], 'message' => $result['message']]);
        }

        $stored = $client->pharmacyCatalog($site, $request->user(), 'POST', 'product-equivalences/proposals', ['pairs' => $result['pairs']]);

        if (! $stored['ok']) {
            return response()->json(['message' => $stored['message'] ?: 'Le site a refusé les propositions.'], 502);
        }

        return response()->json([
            'data' => ['proposed' => (int) data_get($stored, 'data.proposed', count($result['pairs']))],
            'message' => $stored['message'] ?: $result['message'],
        ]);
    }

    private static function runKey(string $run): string
    {
        return 'supplier-ai-run:'.$run;
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
