<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\StaffDebtStatus;
use App\Http\Controllers\Controller;
use App\Models\StaffDebt;
use App\Services\StaffDebts\StaffDebtDirectory;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-228 — les demandes de dette du personnel qui attendent le DG. Le portail les relit
 * pour le prévenir (StaffDebtWatcher). ADR-229 — il les ouvre et les décide dans Finance,
 * par le relais des dettes du personnel (routes/staff_debts.php), et lit ici la vue
 * d'ensemble d'un site. Ni motif ni salaire ici : un nom, un numéro, un montant.
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
                'summary' => ['pending' => count($pending), 'to_disburse' => StaffDebt::query()->where('status', StaffDebtStatus::Approved->value)->count()],
            ],
        ]);
    }

    /** ADR-229 — « Tous les sites » : ce qu'un site a en jeu, sans nom ni motif. */
    public function overview(Request $request, StaffDebtDirectory $directory): JsonResponse
    {
        if (CatalogActor::fromRemoteRequest($request)->cannot('staff_debts.view')) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return response()->json([
            'data' => $directory->overview(),
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }
}
