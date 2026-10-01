<?php

namespace App\Services\Laboratory;

use App\Models\LabBacteriumFamily;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — le référentiel des types de prélèvement et de tube du site. Rien
 * n'est supprimé : une entrée s'archive avec un motif et se restaure ; un nom ou
 * un code déjà porté (archives comprises) ne se recrée pas. Un prélèvement déjà
 * enregistré garde le nom et le tube figés : renommer ne réécrit aucune étiquette.
 */
class LabSampleTypeManager
{
    public const KINDS = ['sample', 'tube'];

    /** @param  array<string, mixed>  $data */
    public function create(string $kind, array $data, User $actor): Model
    {
        $this->authorize($actor, 'lab_sample_types.create');

        return DB::transaction(function () use ($kind, $data, $actor): Model {
            $attributes = $this->attributes($kind, $data);
            $this->ensureUnique($kind, $attributes);

            return $this->modelClass($kind)::create([...$attributes, 'is_active' => true, 'created_by' => $actor->getKey()]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $kind, Model $entry, array $data, User $actor): Model
    {
        $this->authorize($actor, 'lab_sample_types.update');

        return DB::transaction(function () use ($kind, $entry, $data, $actor): Model {
            $attributes = $this->attributes($kind, $data);
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
        $this->authorize($actor, 'lab_sample_types.archive');

        if ($entry instanceof LabTubeType && $entry->sampleTypes()->exists()) {
            throw ValidationException::withMessages(['reason' => 'Ce tube est proposé par un type de prélèvement : changez-le d’abord.']);
        }

        $entry->deleted_by = $actor->getKey();
        $entry->delete_reason = trim($reason);
        $entry->delete();
    }

    public function restore(Model $entry, User $actor): void
    {
        $this->authorize($actor, 'lab_sample_types.restore');
        $entry->restore();
    }

    /** @return class-string<Model> */
    public function modelClass(string $kind): string
    {
        return $kind === 'tube' ? LabTubeType::class : LabSampleType::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(string $kind, array $data): array
    {
        if ($kind === 'tube') {
            return [
                'code' => (string) $data['code'],
                'name' => (string) $data['name'],
                'cap_color' => filled($data['cap_color'] ?? null) ? trim((string) $data['cap_color']) : null,
                'color_hex' => filled($data['color_hex'] ?? null) ? strtolower((string) $data['color_hex']) : null,
            ];
        }

        $tube = null;
        if (filled($data['tube_type_uuid'] ?? null)) {
            $tube = LabTubeType::query()->where('uuid', $data['tube_type_uuid'])->first();
            if ($tube === null) {
                throw ValidationException::withMessages(['tube_type_uuid' => 'Choisissez un tube actif.']);
            }
        }

        return [
            'name' => (string) $data['name'],
            'tube_type_id' => $tube?->id,
            'instructions' => filled($data['instructions'] ?? null) ? trim((string) $data['instructions']) : null,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function ensureUnique(string $kind, array $attributes, ?Model $ignore = null): void
    {
        [$column, $value, $field] = $kind === 'tube'
            ? ['normalized_code', $attributes['code'], 'code']
            : ['normalized_name', $attributes['name'], 'name'];

        $duplicate = $this->modelClass($kind)::withTrashed()
            ->where($column, LabBacteriumFamily::normalize($value))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages([$field => $duplicate->trashed()
                ? 'Ce '.($kind === 'tube' ? 'code' : 'nom').' existe déjà, archivé : restaurez-le plutôt que de le recréer.'
                : 'Ce '.($kind === 'tube' ? 'code' : 'nom').' existe déjà.']);
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException("Cette action demande le droit « {$permission} ».");
        }
    }
}
