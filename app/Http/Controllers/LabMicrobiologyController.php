<?php

namespace App\Http\Controllers;

use App\Models\LabAntibiogram;
use App\Models\LabAntibiogramResult;
use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use App\Services\Laboratory\LabMicrobiologyManager;
use App\Services\Laboratory\LabMicrobiologyStarter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-213 — le référentiel de microbiologie du site (familles, germes,
 * antibiotiques), géré au site par le Laboratoire.
 */
class LabMicrobiologyController extends Controller
{
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archives');
        $user = $request->user();

        $families = LabBacteriumFamily::query()
            ->when($showArchived, fn ($query) => $query->withTrashed())
            ->with([
                'bacteria' => fn ($query) => $query->when($showArchived, fn ($q) => $q->withTrashed()),
                'antibiotics' => fn ($query) => $query->when($showArchived, fn ($q) => $q->withTrashed()),
            ])
            ->orderBy('name')
            ->get();

        $usedBacteria = LabAntibiogram::query()->distinct()->pluck('bacterium_id')->flip();
        $usedAntibiotics = LabAntibiogramResult::query()->distinct()->pluck('antibiotic_id')->flip();

        return Inertia::render('Laboratory/Microbiology', [
            'families' => $families->map(fn (LabBacteriumFamily $family) => [
                'uuid' => $family->uuid,
                'name' => $family->name,
                'is_active' => $family->is_active,
                'archived' => $family->trashed(),
                'delete_reason' => $family->delete_reason,
                'bacteria' => $family->bacteria->map(fn (LabBacterium $bacterium) => $this->entry($bacterium, $usedBacteria->has($bacterium->id)))->values(),
                'antibiotics' => $family->antibiotics->map(fn (LabAntibiotic $antibiotic) => [
                    ...$this->entry($antibiotic, $usedAntibiotics->has($antibiotic->id)),
                    'comment' => $antibiotic->comment,
                ])->values(),
            ])->values(),
            'showArchived' => $showArchived,
            'isEmpty' => LabBacteriumFamily::withTrashed()->doesntExist(),
            'can' => [
                'create' => $user->can('lab_microbiology.create'),
                'update' => $user->can('lab_microbiology.update'),
                'archive' => $user->can('lab_microbiology.archive'),
                'restore' => $user->can('lab_microbiology.restore'),
            ],
        ]);
    }

    public function store(Request $request, string $kind, LabMicrobiologyManager $manager): RedirectResponse
    {
        $this->kind($kind);
        $entry = $manager->create($kind, $request->validate($this->rules($kind)), $request->user());

        return back()->with('status', "« {$entry->name} » ajouté au référentiel.");
    }

    public function update(Request $request, string $kind, string $uuid, LabMicrobiologyManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid);
        $entry = $manager->update($kind, $entry, $request->validate($this->rules($kind, true)), $request->user());

        return back()->with('status', "« {$entry->name} » mis à jour.");
    }

    public function archive(Request $request, string $kind, string $uuid, LabMicrobiologyManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Indiquez le motif de l’archivage.']);
        $manager->archive($entry, $validated['reason'], $request->user());

        return back()->with('status', "« {$entry->name} » archivé.");
    }

    public function restore(Request $request, string $kind, string $uuid, LabMicrobiologyManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid, true);
        $manager->restore($entry, $request->user());

        return back()->with('status', "« {$entry->name} » restauré.");
    }

    public function importStarter(Request $request, LabMicrobiologyStarter $starter): RedirectResponse
    {
        abort_unless($request->user()->can('lab_microbiology.create'), 403);
        $added = $starter->import($request->user());

        return back()->with('status', "Référentiel de départ importé : {$added['families']} famille(s), {$added['bacteria']} germe(s), {$added['antibiotics']} antibiotique(s).");
    }

    /** @return array<string, mixed> */
    private function entry(LabBacterium|LabAntibiotic $entry, bool $used): array
    {
        return [
            'uuid' => $entry->uuid,
            'name' => $entry->name,
            'is_active' => $entry->is_active,
            'archived' => $entry->trashed(),
            'delete_reason' => $entry->delete_reason,
            'used' => $used,
        ];
    }

    /** @return array<string, mixed> */
    private function rules(string $kind, bool $update = false): array
    {
        return [
            'name' => ['required', 'string', 'max:'.($kind === 'family' ? 150 : 200)],
            'family_uuid' => $kind === 'family' ? ['prohibited'] : ['required', 'uuid', Rule::exists('lab_bacterium_families', 'uuid')->whereNull('deleted_at')],
            'comment' => $kind === 'antibiotic' ? ['nullable', 'string', 'max:500'] : ['prohibited'],
            'is_active' => $update ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    private function kind(string $kind): void
    {
        abort_unless(in_array($kind, LabMicrobiologyManager::KINDS, true), 404);
    }

    private function find(LabMicrobiologyManager $manager, string $kind, string $uuid, bool $archived = false): Model
    {
        $this->kind($kind);
        $class = $manager->modelClass($kind);

        return $class::query()->when($archived, fn ($query) => $query->onlyTrashed())->where('uuid', $uuid)->firstOrFail();
    }
}
