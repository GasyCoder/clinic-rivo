<?php

namespace App\Http\Controllers;

use App\Models\LabSample;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Services\Laboratory\LabSampleStarter;
use App\Services\Laboratory\LabSampleTypeManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-214 — le référentiel des types de prélèvement et de tube du site, géré au
 * site par le Laboratoire. Aucun prix : facturer un prélèvement, c'est une
 * prestation du catalogue au tarif du Super Admin (ADR-024).
 */
class LabSampleTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $showArchived = $request->boolean('archives');
        $user = $request->user();
        $usedSamples = LabSample::query()->distinct()->pluck('sample_type_id')->flip();
        $usedTubes = LabSample::query()->whereNotNull('tube_type_id')->distinct()->pluck('tube_type_id')->flip();

        return Inertia::render('Laboratory/SampleTypes', [
            'sampleTypes' => LabSampleType::query()
                ->when($showArchived, fn ($query) => $query->withTrashed())
                ->with('tubeType')
                ->orderBy('name')
                ->get()
                ->map(fn (LabSampleType $type) => [
                    'uuid' => $type->uuid,
                    'name' => $type->name,
                    'instructions' => $type->instructions,
                    'tube' => $type->tubeType ? ['uuid' => $type->tubeType->uuid, 'code' => $type->tubeType->code, 'name' => $type->tubeType->name, 'hex' => $type->tubeType->color_hex, 'color' => $type->tubeType->cap_color] : null,
                    'is_active' => $type->is_active,
                    'archived' => $type->trashed(),
                    'delete_reason' => $type->delete_reason,
                    'used' => $usedSamples->has($type->id),
                ])->values(),
            'tubes' => LabTubeType::query()
                ->when($showArchived, fn ($query) => $query->withTrashed())
                ->withCount('sampleTypes')
                ->orderBy('code')
                ->get()
                ->map(fn (LabTubeType $tube) => [
                    'uuid' => $tube->uuid,
                    'code' => $tube->code,
                    'name' => $tube->name,
                    'cap_color' => $tube->cap_color,
                    'color_hex' => $tube->color_hex,
                    'is_active' => $tube->is_active,
                    'archived' => $tube->trashed(),
                    'delete_reason' => $tube->delete_reason,
                    'used' => $usedTubes->has($tube->id),
                    'sample_types_count' => $tube->sample_types_count,
                ])->values(),
            'showArchived' => $showArchived,
            'isEmpty' => LabTubeType::withTrashed()->doesntExist() && LabSampleType::withTrashed()->doesntExist(),
            'can' => [
                'create' => $user->can('lab_sample_types.create'),
                'update' => $user->can('lab_sample_types.update'),
                'archive' => $user->can('lab_sample_types.archive'),
                'restore' => $user->can('lab_sample_types.restore'),
            ],
        ]);
    }

    public function store(Request $request, string $kind, LabSampleTypeManager $manager): RedirectResponse
    {
        $this->kind($kind);
        $entry = $manager->create($kind, $request->validate($this->rules($kind)), $request->user());

        return back()->with('status', '« '.$this->label($entry).' » ajouté au référentiel.');
    }

    public function update(Request $request, string $kind, string $uuid, LabSampleTypeManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid);
        $entry = $manager->update($kind, $entry, $request->validate($this->rules($kind, true)), $request->user());

        return back()->with('status', '« '.$this->label($entry).' » mis à jour.');
    }

    public function archive(Request $request, string $kind, string $uuid, LabSampleTypeManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Indiquez le motif de l’archivage.']);
        $manager->archive($entry, $validated['reason'], $request->user());

        return back()->with('status', '« '.$this->label($entry).' » archivé.');
    }

    public function restore(Request $request, string $kind, string $uuid, LabSampleTypeManager $manager): RedirectResponse
    {
        $entry = $this->find($manager, $kind, $uuid, true);
        $manager->restore($entry, $request->user());

        return back()->with('status', '« '.$this->label($entry).' » restauré.');
    }

    public function importStarter(Request $request, LabSampleStarter $starter): RedirectResponse
    {
        abort_unless($request->user()->can('lab_sample_types.create'), 403);
        $added = $starter->import($request->user());

        return back()->with('status', "Référentiel de départ importé : {$added['tubes']} tube(s), {$added['samples']} type(s) de prélèvement.");
    }

    /** @return array<string, mixed> */
    private function rules(string $kind, bool $update = false): array
    {
        return $kind === 'tube'
            ? [
                'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
                'name' => ['required', 'string', 'max:120'],
                'cap_color' => ['nullable', 'string', 'max:60'],
                'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_active' => $update ? ['sometimes', 'boolean'] : ['prohibited'],
            ]
            : [
                'name' => ['required', 'string', 'max:150'],
                'tube_type_uuid' => ['nullable', 'uuid'],
                'instructions' => ['nullable', 'string', 'max:500'],
                'is_active' => $update ? ['sometimes', 'boolean'] : ['prohibited'],
            ];
    }

    private function label(Model $entry): string
    {
        return $entry instanceof LabTubeType ? "{$entry->code} — {$entry->name}" : (string) $entry->name;
    }

    private function kind(string $kind): void
    {
        abort_unless(in_array($kind, LabSampleTypeManager::KINDS, true), 404);
    }

    private function find(LabSampleTypeManager $manager, string $kind, string $uuid, bool $archived = false): Model
    {
        $this->kind($kind);
        $class = $manager->modelClass($kind);

        return $class::query()->when($archived, fn ($query) => $query->onlyTrashed())->where('uuid', $uuid)->firstOrFail();
    }
}
