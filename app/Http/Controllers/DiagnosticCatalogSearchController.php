<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiagnosticCatalogSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = str($validated['q'] ?? '')->squish()->toString();

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $escaped = addcslashes($term, '%_\\');

        $diagnostics = DiagnosticCatalog::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$escaped}%")
                ->orWhere('code', 'like', "%{$escaped}%"))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [mb_strtoupper($term)])
            ->orderBy('name')
            ->limit(12)
            ->get(['uuid', 'code', 'name', 'category', 'description']);

        return response()->json(['data' => $diagnostics]);
    }
}
