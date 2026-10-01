<?php

namespace App\Http\Controllers;

use App\Models\AnalysisCatalog;
use App\Models\LabDiscipline;
use App\Services\Laboratory\LabDisciplineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-238 — les disciplines du laboratoire du site : créer, renommer,
 * réordonner, fusionner, archiver, restaurer. Monté dans `routes/laboratory.php`,
 * donc servi aussi au portail par l'API du site (ADR-215).
 */
class LabDisciplineController extends Controller
{
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archives');
        $user = $request->user();
        $counts = AnalysisCatalog::query()
            ->whereNotNull('lab_discipline_id')
            ->selectRaw('lab_discipline_id, count(*) as total, sum(case when parent_id is null then 1 else 0 end) as roots')
            ->groupBy('lab_discipline_id')
            ->get()
            ->keyBy('lab_discipline_id');

        return Inertia::render('Laboratory/Disciplines', [
            'disciplines' => LabDiscipline::query()
                ->when($showArchived, fn ($query) => $query->withTrashed())
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->map(fn (LabDiscipline $discipline) => [
                    'uuid' => $discipline->uuid,
                    'name' => $discipline->name,
                    'display_order' => $discipline->display_order,
                    'is_active' => $discipline->is_active,
                    'archived' => $discipline->trashed(),
                    'delete_reason' => $discipline->delete_reason,
                    'analyses_count' => (int) ($counts->get($discipline->id)?->total ?? 0),
                    'roots_count' => (int) ($counts->get($discipline->id)?->roots ?? 0),
                ])->values(),
            'withoutDiscipline' => AnalysisCatalog::query()->whereNull('parent_id')->whereNull('lab_discipline_id')->count(),
            'showArchived' => $showArchived,
            'can' => [
                'create' => $user->can('lab_disciplines.create'),
                'update' => $user->can('lab_disciplines.update'),
                'archive' => $user->can('lab_disciplines.archive'),
                'restore' => $user->can('lab_disciplines.restore'),
                'merge' => $user->can('lab_disciplines.update') && $user->can('lab_disciplines.archive'),
            ],
        ]);
    }

    public function store(Request $request, LabDisciplineManager $manager): RedirectResponse
    {
        $discipline = $manager->create($request->validate($this->rules()), $request->user());

        return back()->with('status', "« {$discipline->name} » ajoutée.");
    }

    public function update(Request $request, string $uuid, LabDisciplineManager $manager): RedirectResponse
    {
        $discipline = $manager->update($this->find($uuid), $request->validate($this->rules(true)), $request->user());

        return back()->with('status', "« {$discipline->name} » mise à jour.");
    }

    public function archive(Request $request, string $uuid, LabDisciplineManager $manager): RedirectResponse
    {
        $discipline = $this->find($uuid);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Indiquez le motif de l’archivage.']);
        $manager->archive($discipline, $validated['reason'], $request->user());

        return back()->with('status', "« {$discipline->name} » archivée.");
    }

    public function restore(Request $request, string $uuid, LabDisciplineManager $manager): RedirectResponse
    {
        $discipline = $this->find($uuid, true);
        $manager->restore($discipline, $request->user());

        return back()->with('status', "« {$discipline->name} » restaurée.");
    }

    public function merge(Request $request, string $uuid, LabDisciplineManager $manager): RedirectResponse
    {
        $source = $this->find($uuid);
        $validated = $request->validate(['target_uuid' => ['required', 'uuid']], ['target_uuid.*' => 'Choisissez la discipline qui la remplace.']);
        $target = LabDiscipline::withTrashed()->where('uuid', $validated['target_uuid'])->firstOrFail();
        $moved = $manager->merge($source, $target, $request->user());

        return back()->with('status', "« {$source->name} » fusionnée avec « {$target->name} » : {$moved} analyse(s) déplacée(s).");
    }

    /** @return array<string, mixed> */
    private function rules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:120'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => $update ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    private function find(string $uuid, bool $archived = false): LabDiscipline
    {
        return LabDiscipline::query()->when($archived, fn ($query) => $query->onlyTrashed())->where('uuid', $uuid)->firstOrFail();
    }
}
