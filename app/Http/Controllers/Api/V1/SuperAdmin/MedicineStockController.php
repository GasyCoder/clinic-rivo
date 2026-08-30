<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Pharmacy\MedicineStockImportService;
use App\Services\Pharmacy\MedicineStockOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MedicineStockController extends Controller
{
    public function __invoke(MedicineStockOverviewService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->overview(),
            'meta' => [
                'site' => [
                    'code' => config('rivo.site.code'),
                    'name' => config('rivo.site.name'),
                ],
                'generated_at' => now()->toIso8601String(),
                'scope' => 'READ_ONLY',
            ],
        ]);
    }

    public function import(Request $request, MedicineStockImportService $service): JsonResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.code_medicament' => ['required', 'string', 'max:100'],
            'rows.*.numero_lot' => ['required', 'string', 'max:100'],
            'rows.*.operation' => ['required', Rule::in(['STOCK_INITIAL', 'ENTREE'])],
            'rows.*.quantite' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'rows.*.date_reception' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.date_peremption' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'rows.*.motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $actorUuid = (string) $request->attributes->get('rivo_actor_uuid');
        $actorName = str((string) $request->attributes->get('rivo_actor_name'))->squish()->toString();

        if (! Str::isUuid($actorUuid) || $actorName === '') {
            throw ValidationException::withMessages([
                'actor' => 'L’identité du Super Administrateur est obligatoire pour importer un stock.',
            ]);
        }

        $result = $service->import(
            $validated['rows'],
            $actorUuid,
            $actorName,
            (string) $request->header('Idempotency-Key'),
        );

        return response()->json([
            'message' => sprintf(
                '%d ligne(s) Excel importée(s), %d unité(s) ajoutée(s).',
                $result['rows'],
                $result['quantity'],
            ),
            'data' => $result,
        ]);
    }
}
