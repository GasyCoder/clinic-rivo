<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreDiagnosticCatalogRequest;
use App\Http\Requests\Administration\UpdateDiagnosticCatalogRequest;
use App\Models\DiagnosticCatalog;
use App\Services\Medicine\DiagnosticCatalogManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosticCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'inactive', 'all'], true)
            ? $request->query('status')
            : 'active';
        $search = str($request->query('q', ''))->squish()->toString();

        $diagnostics = DiagnosticCatalog::query()
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")))
            ->withCount('diagnoses')
            ->orderBy('name')
            ->get()
            ->map(fn (DiagnosticCatalog $catalog): array => [
                'uuid' => $catalog->uuid,
                'code' => $catalog->code,
                'name' => $catalog->name,
                'description' => $catalog->description,
                'category' => $catalog->category,
                'is_active' => $catalog->is_active,
                'diagnoses_count' => $catalog->diagnoses_count,
            ]);

        return Inertia::render('Administration/Diagnostics/Index', [
            'diagnostics' => $diagnostics,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => [
                'active' => DiagnosticCatalog::query()->where('is_active', true)->count(),
                'inactive' => DiagnosticCatalog::query()->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(StoreDiagnosticCatalogRequest $request, DiagnosticCatalogManager $manager): RedirectResponse
    {
        $catalog = $manager->create($request->validated());

        return back()->with('status', "Diagnostic « {$catalog->name} » ajouté au référentiel.");
    }

    public function update(
        UpdateDiagnosticCatalogRequest $request,
        DiagnosticCatalog $diagnosticCatalog,
        DiagnosticCatalogManager $manager,
    ): RedirectResponse {
        $catalog = $manager->update($diagnosticCatalog, $request->validated());

        return back()->with('status', "Diagnostic « {$catalog->name} » mis à jour.");
    }

    public function activate(DiagnosticCatalog $diagnosticCatalog, DiagnosticCatalogManager $manager): RedirectResponse
    {
        $manager->activate($diagnosticCatalog);

        return back()->with('status', "Diagnostic « {$diagnosticCatalog->name} » activé.");
    }

    public function deactivate(DiagnosticCatalog $diagnosticCatalog, DiagnosticCatalogManager $manager): RedirectResponse
    {
        $manager->deactivate($diagnosticCatalog);

        return back()->with('status', "Diagnostic « {$diagnosticCatalog->name} » désactivé.");
    }
}
