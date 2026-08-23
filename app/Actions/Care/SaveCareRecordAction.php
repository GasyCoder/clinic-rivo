<?php

namespace App\Actions\Care;

use App\Actions\Patient\RecordPatientAllergyAction;
use App\Enums\AllergySeverity;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\AllergenReference;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaveCareRecordAction
{
    public function __construct(private readonly RecordPatientAllergyAction $recordAllergy) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): CareRecord
    {
        return DB::transaction(function () use ($orientation, $data, $actor): CareRecord {
            $locked = EpisodeOrientation::query()
                ->with(['episode.careRecord', 'episode.patient.allergies'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Soins.');
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress
                || $locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'care_record' => 'La fiche est modifiable uniquement pendant une prise en charge active aux Soins.',
                ]);
            }

            $procedures = collect($data['procedures'] ?? []);
            $attributes = $this->recordAttributes($data);
            $attributes = $this->appendAllergyAttributes(
                $attributes,
                $locked->episode->patient,
                $data,
                $actor,
            );

            if ($locked->episode->careRecord === null
                && $procedures->isEmpty()
                && collect($attributes)->every(fn ($value) => $value === null)) {
                throw ValidationException::withMessages([
                    'care_record' => 'Renseignez au moins une information ou un acte réalisé.',
                ]);
            }

            $record = $locked->episode->careRecord;

            if ($record) {
                $record->fill($attributes);
                $record->updated_by = $actor->getKey();
                $record->save();
            } else {
                $record = CareRecord::query()->create([
                    'episode_id' => $locked->episode->getKey(),
                    ...$attributes,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
            }

            $this->appendProcedures($record, $procedures, $actor);

            return $record->fresh(['procedures.performer', 'creator', 'updater']);
        });
    }

    /** @param array<string, mixed> $data */
    private function recordAttributes(array $data): array
    {
        $height = $data['height_cm'] ?? null;
        $weight = $data['weight_kg'] ?? null;

        return [
            'blood_group' => $data['blood_group'] ?? null,
            'height_cm' => $height,
            'weight_kg' => $weight,
            'bmi' => $this->calculateBmi($height, $weight),
            'smoker' => array_key_exists('smoker', $data) ? $data['smoker'] : null,
            'hospitalization_reason' => $this->nullableText($data['hospitalization_reason'] ?? null),
            'hospitalized_at' => $data['hospitalized_at'] ?? null,
            'discharged_at' => $data['discharged_at'] ?? null,
            'diagnostic_note' => $this->nullableText($data['diagnostic_note'] ?? null),
            'transmission_reason' => $this->nullableText($data['transmission_reason'] ?? null),
        ] + (array_key_exists('allergy_note', $data) ? [
            'allergy_note' => $this->nullableText($data['allergy_note']),
        ] : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function appendAllergyAttributes(
        array $attributes,
        Patient $patient,
        array $data,
        User $actor,
    ): array {
        $hasSelection = array_key_exists('allergy_uuids', $data);
        $hasReferenceSelection = array_key_exists('allergen_reference_uuids', $data);
        $hasNewAllergies = array_key_exists('new_allergies', $data);

        if (! $hasSelection && ! $hasReferenceSelection && ! $hasNewAllergies) {
            return $attributes;
        }

        if (! $actor->can('patients.medical_history.view')) {
            throw new AuthorizationException('Vous ne pouvez pas consulter les allergies du patient.');
        }

        $newAllergies = collect($data['new_allergies'] ?? []);
        $referenceUuids = collect($data['allergen_reference_uuids'] ?? [])->unique()->values();

        if (($newAllergies->isNotEmpty() || $referenceUuids->isNotEmpty())
            && ! $actor->can('patients.medical_history.manage')) {
            throw new AuthorizationException('Vous ne pouvez pas ajouter une allergie au dossier patient.');
        }

        $selectedUuids = collect($data['allergy_uuids'] ?? [])->unique()->values();
        $selected = $patient->allergies->whereIn('uuid', $selectedUuids)->values();

        if ($selected->count() !== $selectedUuids->count()) {
            throw ValidationException::withMessages([
                'allergy_uuids' => 'Une allergie sélectionnée n’appartient pas à ce patient ou n’est plus disponible.',
            ]);
        }

        $knownBySubstance = $patient->allergies
            ->keyBy(fn (PatientAllergy $allergy) => $this->normalizeAllergyName($allergy->substance));

        $references = AllergenReference::query()
            ->where('active', true)
            ->whereIn('uuid', $referenceUuids)
            ->lockForUpdate()
            ->get();

        if ($references->count() !== $referenceUuids->count()) {
            throw ValidationException::withMessages([
                'allergen_reference_uuids' => 'Un allergène sélectionné n’est plus disponible dans le référentiel.',
            ]);
        }

        foreach ($references as $reference) {
            $normalized = $this->normalizeAllergyName($reference->name);
            $allergy = $knownBySubstance->get($normalized);

            if (! $allergy) {
                $allergy = $this->recordAllergy->execute(
                    patient: $patient,
                    substance: $reference->name,
                    actor: $actor,
                    reference: $reference,
                );
                $knownBySubstance->put($normalized, $allergy);
            } elseif ($allergy->allergen_reference_id === null) {
                $allergy->allergen_reference_id = $reference->getKey();
                $allergy->save();
            }

            $selected->push($allergy);
        }

        foreach ($newAllergies as $newAllergy) {
            $substance = trim((string) ($newAllergy['substance'] ?? ''));
            $normalized = $this->normalizeAllergyName($substance);

            if ($normalized === '') {
                continue;
            }

            $allergy = $knownBySubstance->get($normalized);

            if (! $allergy) {
                $allergy = $this->recordAllergy->execute(
                    $patient,
                    $substance,
                    $this->nullableText($newAllergy['reaction'] ?? null),
                    filled($newAllergy['severity'] ?? null)
                        ? AllergySeverity::from($newAllergy['severity'])
                        : null,
                    $actor,
                );
                $knownBySubstance->put($normalized, $allergy);
            }

            $selected->push($allergy);
        }

        $snapshot = $selected
            ->unique('uuid')
            ->map(fn (PatientAllergy $allergy) => [
                'uuid' => $allergy->uuid,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                'severity' => $allergy->severity?->value,
            ])
            ->values()
            ->all();

        $attributes['allergy_snapshot'] = $snapshot === [] ? null : $snapshot;

        return $attributes;
    }

    private function normalizeAllergyName(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $procedures
     */
    private function appendProcedures(CareRecord $record, Collection $procedures, User $actor): void
    {
        if ($procedures->isEmpty()) {
            return;
        }

        $items = CatalogItem::query()
            ->whereIn('uuid', $procedures->pluck('catalog_item_uuid'))
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Care->value)
            ->lockForUpdate()
            ->get()
            ->keyBy('uuid');

        if ($items->count() !== $procedures->count()) {
            throw ValidationException::withMessages([
                'procedures' => 'Un acte sélectionné est devenu indisponible. Actualisez la fiche.',
            ]);
        }

        foreach ($procedures as $index => $procedure) {
            $item = $items->get($procedure['catalog_item_uuid']);
            $notes = $this->nullableText(Arr::get($procedure, 'notes'));

            if ($item->code === 'CARE-OTHER' && $notes === null) {
                throw ValidationException::withMessages([
                    "procedures.{$index}.notes" => 'Précisez l’acte réalisé lorsque vous choisissez « Autres ».',
                ]);
            }

            $record->procedures()->create([
                'catalog_item_id' => $item->getKey(),
                'catalog_item_uuid' => $item->uuid,
                'procedure_code' => $item->code,
                'procedure_name' => $item->name,
                'quantity' => $procedure['quantity'],
                'notes' => $notes,
                'performed_by' => $actor->getKey(),
                'performed_at' => now(),
            ]);
        }
    }

    private function calculateBmi(int|float|string|null $height, int|float|string|null $weight): ?string
    {
        if ($height === null || $weight === null || (float) $height <= 0 || (float) $weight <= 0) {
            return null;
        }

        $heightInMeters = (float) $height / 100;

        return number_format((float) $weight / ($heightInMeters ** 2), 2, '.', '');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
