<?php

namespace App\Http\Controllers\Medicine;

use App\Actions\Medicine\SaveClinicalProtocolAction;
use App\Enums\AdministrationRoute;
use App\Http\Controllers\Controller;
use App\Http\Requests\Medicine\SaveClinicalProtocolRequest;
use App\Models\ClinicalProtocol;
use App\Models\ClinicalProtocolLine;
use App\Models\DiagnosticCatalog;
use App\Services\Pharmacy\MedicineStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-111 — les protocoles thérapeutiques de la clinique.
 *
 * Le contrôleur ne porte aucune règle : l'enregistrement passe par
 * `SaveClinicalProtocolAction`, l'archivage par le Soft Delete tracé du
 * modèle (ADR-009).
 */
class ClinicalProtocolController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'inactive', 'archived'], true)
            ? $request->query('status')
            : 'active';
        $search = str($request->query('q', ''))->squish()->toString();

        $protocols = ClinicalProtocol::query()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status !== 'archived', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('diagnosticCatalog', fn ($catalog) => $catalog
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"))))
            ->with(['diagnosticCatalog:id,uuid,code,name', 'lines.medicine.catalogItem:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (ClinicalProtocol $protocol): array => [
                'uuid' => $protocol->uuid,
                'name' => $protocol->name,
                'diagnosis' => $protocol->diagnosticCatalog?->name,
                'diagnosis_code' => $protocol->diagnosticCatalog?->code,
                'population' => $this->population($protocol),
                'indications' => $protocol->indications ?? [],
                'medicines' => $protocol->lines->map(fn ($line) => $line->medicine?->catalogItem?->name)->filter()->values(),
                'is_active' => $protocol->is_active,
                'archived' => $protocol->trashed(),
                'delete_reason' => $protocol->delete_reason,
            ]);

        return Inertia::render('Medicine/Protocols/Index', [
            'protocols' => $protocols,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => [
                'active' => ClinicalProtocol::query()->where('is_active', true)->count(),
                'inactive' => ClinicalProtocol::query()->where('is_active', false)->count(),
                'archived' => ClinicalProtocol::onlyTrashed()->count(),
            ],
            'can_manage' => $request->user()->can('clinical_protocols.manage'),
        ]);
    }

    public function create(MedicineStockService $stock): Response
    {
        return $this->form(null, $stock);
    }

    public function edit(ClinicalProtocol $clinicalProtocol, MedicineStockService $stock): Response
    {
        return $this->form($clinicalProtocol, $stock);
    }

    public function store(SaveClinicalProtocolRequest $request, SaveClinicalProtocolAction $action): RedirectResponse
    {
        $protocol = $action->execute(null, $request->validated(), $request->user());

        return redirect()->route('medicine.protocols.index')
            ->with('status', "Protocole « {$protocol->name} » enregistré.");
    }

    public function update(
        SaveClinicalProtocolRequest $request,
        ClinicalProtocol $clinicalProtocol,
        SaveClinicalProtocolAction $action,
    ): RedirectResponse {
        $protocol = $action->execute($clinicalProtocol, $request->validated(), $request->user());

        return redirect()->route('medicine.protocols.index')
            ->with('status', "Protocole « {$protocol->name} » mis à jour.");
    }

    public function archive(Request $request, ClinicalProtocol $clinicalProtocol): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Indiquez pourquoi ce protocole est archivé.'],
        );

        // Archiver n'efface rien : un diagnostic ou une ligne d'ordonnance
        // qui l'ont eu pour origine continuent de le désigner (ADR-010).
        $clinicalProtocol->delete_reason = trim($data['reason']);
        $clinicalProtocol->delete();

        return back()->with('status', "Protocole « {$clinicalProtocol->name} » archivé.");
    }

    public function restore(ClinicalProtocol $clinicalProtocol): RedirectResponse
    {
        if (! $clinicalProtocol->trashed()) {
            throw ValidationException::withMessages(['protocol' => 'Ce protocole n’est pas archivé.']);
        }

        $clinicalProtocol->restore();

        return back()->with('status', "Protocole « {$clinicalProtocol->name} » restauré.");
    }

    private function form(?ClinicalProtocol $protocol, MedicineStockService $stock): Response
    {
        $protocol?->load(['diagnosticCatalog:id,uuid', 'lines.medicine.catalogItem:id,uuid']);

        return Inertia::render('Medicine/Protocols/Form', [
            'protocol' => $protocol === null ? null : [
                'uuid' => $protocol->uuid,
                'diagnostic_catalog_uuid' => $protocol->diagnosticCatalog?->uuid,
                'name' => $protocol->name,
                'indications' => $protocol->indications ?? [],
                'min_age_years' => $protocol->min_age_years,
                'max_age_years' => $protocol->max_age_years,
                'sex' => $protocol->sex?->value,
                'min_weight_kg' => $protocol->min_weight_kg !== null ? (float) $protocol->min_weight_kg : null,
                'max_weight_kg' => $protocol->max_weight_kg !== null ? (float) $protocol->max_weight_kg : null,
                'notes' => $protocol->notes,
                'is_active' => $protocol->is_active,
                'lines' => $protocol->lines->map(fn (ClinicalProtocolLine $line) => [
                    'medicine_uuid' => $line->medicine?->catalogItem?->uuid,
                    'dosage' => $line->dosage,
                    'route' => $line->route?->value,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'quantity' => $line->quantity,
                    'instructions' => $line->instructions,
                ])->values(),
            ],
            'diagnostics' => DiagnosticCatalog::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['uuid', 'code', 'name'])
                ->map(fn (DiagnosticCatalog $catalog) => [
                    'value' => $catalog->uuid,
                    'label' => $catalog->code ? "{$catalog->name} ({$catalog->code})" : $catalog->name,
                ]),
            'medicines' => $stock->availableCatalog()->values(),
            'administration_routes' => collect(AdministrationRoute::cases())->map(fn (AdministrationRoute $route) => [
                'value' => $route->value,
                'label' => $route->label(),
                'short_label' => $route->shortLabel(),
            ])->values(),
        ]);
    }

    private function population(ClinicalProtocol $protocol): string
    {
        $parts = [];

        if ($protocol->min_age_years !== null || $protocol->max_age_years !== null) {
            $parts[] = match (true) {
                $protocol->min_age_years !== null && $protocol->max_age_years !== null => "{$protocol->min_age_years}–{$protocol->max_age_years} ans",
                $protocol->min_age_years !== null => "{$protocol->min_age_years} ans et plus",
                default => "jusqu’à {$protocol->max_age_years} ans",
            };
        }

        if ($protocol->sex !== null) {
            $parts[] = $protocol->sex->value === 'F' ? 'femmes' : 'hommes';
        }

        if ($protocol->min_weight_kg !== null || $protocol->max_weight_kg !== null) {
            $min = $protocol->min_weight_kg !== null ? (float) $protocol->min_weight_kg : null;
            $max = $protocol->max_weight_kg !== null ? (float) $protocol->max_weight_kg : null;
            $parts[] = match (true) {
                $min !== null && $max !== null => "{$min}–{$max} kg",
                $min !== null => "{$min} kg et plus",
                default => "jusqu’à {$max} kg",
            };
        }

        return $parts === [] ? 'Tous les patients' : implode(' · ', $parts);
    }
}
