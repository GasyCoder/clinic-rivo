<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\StaffDebts\StaffDebtDirectory;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-228 — les demandes de dette du personnel qui attendent le DG. Le portail les relit
 * pour le prévenir (StaffDebtWatcher) ; il les ouvre et les décide par le relais RH
 * (ADR-187). Ni motif ni salaire ici : un nom, un numéro, un montant.
 */
class StaffDebtController extends Controller
{
    public function pending(Request $request, StaffDebtDirectory $directory): JsonResponse
    {
        if (CatalogActor::fromRemoteRequest($request)->cannot('staff_debts.decide')) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        $pending = $directory->pending();

        return response()->json([
            'data' => ['pending' => $pending],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => ['pending' => count($pending)],
            ],
        ]);
    }
}
