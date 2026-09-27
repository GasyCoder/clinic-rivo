<?php

namespace App\Http\Controllers;

use App\Services\Notifications\AttentionDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le contenu du panneau d'attention, demandé à l'ouverture.
 *
 * Volontairement pas une prop Inertia partagée : ces compteurs interrogent
 * la base, et les calculer à chaque navigation ferait payer à toutes les
 * pages un panneau que personne n'ouvre la plupart du temps.
 */
class AttentionDigestController extends Controller
{
    public function __invoke(Request $request, AttentionDigest $digest): JsonResponse
    {
        return response()->json($digest->forUser($request->user()));
    }
}
