<?php

namespace App\Http\Controllers\Reception;

use App\Enums\ReceptionCartKind;
use App\Http\Controllers\Controller;
use App\Services\Reception\ReceptionEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceptionEstimateController extends Controller
{
    public function __invoke(Request $request, ReceptionEstimateService $estimates): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            // ADR-104 — sans cette clé, `validate()` la retirerait du
            // tableau validé et chaque ligne retomberait sur « prestation » :
            // un médicament serait alors refusé comme non proposé à la
            // Réception, sans qu'aucun message ne dise pourquoi.
            'lines.*.kind' => ['sometimes', Rule::enum(ReceptionCartKind::class)],
            'lines.*.catalog_item_uuid' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'price' => ['prohibited'],
            'total' => ['prohibited'],
            'covered_amount' => ['prohibited'],
            'patient_amount' => ['prohibited'],
            'lines.*.unit_price' => ['prohibited'],
            'lines.*.line_total' => ['prohibited'],
            'lines.*.covered_amount' => ['prohibited'],
            'lines.*.patient_amount' => ['prohibited'],
        ]);

        return response()->json($estimates->estimate($validated['lines']));
    }
}
