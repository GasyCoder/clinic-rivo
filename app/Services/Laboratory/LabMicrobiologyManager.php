<?php

namespace App\Services\Laboratory;

use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — le référentiel de microbiologie du site : familles, germes,
 * antibiotiques. Rien n'est supprimé : une entrée s'archive avec un motif et
 * se restaure. Un nom déjà porté (archives comprises) ne se recrée pas — il se
 * restaure. Un antibiogramme déjà saisi garde le nom figé du germe et de
 * l'antibiotique : renommer ne réécrit aucun résultat.
 */
class LabMicrobiologyManager
{
    public const KINDS = ['family', 'bacterium', 'antibiotic'];

    /** @param  array<string, mixed>  $data */
    public function create(string $kind, array $data, User $actor): Model
    {
        $this->authorize($actor, 'lab_microbiology.create');

        return DB::transaction(function () use ($kind, $data, $actor): Model {
            $attributes = $this->attributes($kind, $data);
            $this->ensureUnique($kind, $attributes);

            return $this->modelClass($kind)::create([...$attributes, 'is_active' => true, 'created_by' => $actor->getKey()]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $kind, Model $entry, array $data, User $actor): Model
    {
        $this->authorize($actor, 'lab_microbiology.update');

        return DB::transaction(function () use ($kind, $entry, $data, $actor): Model {
            $attributes = $this->attributes($kind, $data, $entry);
            $this->ensureUnique($kind, $attributes, $entry);

            $entry->update([
                ...$attributes,
                'is_active' => (bool) ($data['is_active'] ?? $entry->is_active),
                'updated_by' => $actor->getKey(),
            ]);

            return $entry->fresh();
        });
    }

    public function archive(Model $entry, string $reason, User $actor): void
    {
        $this->authorize($actor, 'lab_microbiology.archive');

        if ($entry instanceof LabBacteriumFamily
            && ($entry->bacteria()->exists() || $entry->antibiotics()->exists())) {
            throw ValidationException::withMessages(['reason' => 'Archivez d’abord les germes et antibiotiques de cette famille.']);
        }

        $entry->deleted_by = $actor->getKey();
        $entry->delete_reason = trim($reason);
        $entry->delete();
    }

    public function restore(Model $entry, User $actor): void
    {
        $this->authorize($actor, 'lab_microbiology.restore');

        if (($entry instanceof LabBacterium || $entry instanceof LabAntibiotic)
            && $entry->family()->first()?->trashed()) {
            throw ValidationException::withMessages(['entry' => 'Restaurez d’abord sa famille.']);
        }

        $entry->restore();
    }

    /** @return class-string<Model> */
    public function modelClass(string $kind): string
    {
        return match ($kind) {
            'family' => LabBacteriumFamily::class,
            'bacterium' => LabBacterium::class,
            'antibiotic' => LabAntibiotic::class,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(string $kind, array $data, ?Model $entry = null): array
    {
        $attributes = ['name' => (string) $data['name']];

        if ($kind !== 'family') {
            $family = LabBacteriumFamily::query()->where('uuid', $data['family_uuid'] ?? '')->first();
            if ($family === null) {
                throw ValidationException::withMessages(['family_uuid' => 'Choisissez une famille active.']);
            }
            $attributes['family_id'] = $family->id;
        }

        if ($kind === 'antibiotic') {
            $attributes['comment'] = filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null;
        }

        return $attributes;
    }

    /** @param  array<string, mixed>  $attributes */
    private function ensureUnique(string $kind, array $attributes, ?Model $ignore = null): void
    {
        $class = $this->modelClass($kind);
        $duplicate = $class::withTrashed()
            ->where('normalized_name', LabBacteriumFamily::normalize($attributes['name']))
            ->when($kind !== 'family', fn ($query) => $query->where('family_id', $attributes['family_id']))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['name' => $duplicate->trashed()
                ? 'Ce nom existe déjà, archivé : restaurez-le plutôt que de le recréer.'
                : 'Ce nom existe déjà.']);
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException("Cette action demande le droit « {$permission} ».");
        }
    }
}
