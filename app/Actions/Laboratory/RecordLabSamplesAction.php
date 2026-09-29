<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\LabSample;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — enregistrer les prélèvements d'une demande reçue : un par tube,
 * chacun son code-barres (numéro de laboratoire + rang : A-L26-00042-1).
 *
 * Le tube proposé est celui du type de prélèvement ; le préleveur peut en
 * choisir un autre. Type et tube sont figés sur le prélèvement. Aucun prix : un
 * prélèvement facturé est une prestation du catalogue (ADR-024).
 */
class RecordLabSamplesAction
{
    public const MAX_PER_GESTURE = 20;

    /**
     * @param  array<int, array<string, mixed>>  $lines  {sample_type_uuid, tube_type_uuid?, quantity?, notes?}
     * @return Collection<int, LabSample>
     */
    public function execute(LabRequest $request, array $lines, User $actor): Collection
    {
        if ($actor->cannot('laboratory_samples.create')) {
            throw new AuthorizationException('Enregistrer un prélèvement demande le droit « laboratory_samples.create ».');
        }

        return DB::transaction(function () use ($request, $lines, $actor): Collection {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['samples' => 'Cette demande a été retirée par le prescripteur : elle ne se prélève plus.']);
            }
            // ADR-217 — prélever prend la demande en charge si personne ne l'a encore fait.
            LabItemGuard::ensureTakenUp($locked, $actor);

            return $this->create($locked, $lines, $actor);
        });
    }

    /**
     * Sans contrôle d'accès ni verrou : appelée par la réception, qui les a déjà faits.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, LabSample>
     */
    public function create(LabRequest $request, array $lines, User $actor): Collection
    {
        $expanded = [];
        foreach (array_values($lines) as $index => $line) {
            $type = LabSampleType::query()->where('uuid', $line['sample_type_uuid'] ?? '')->where('is_active', true)->with('tubeType')->first();
            if ($type === null) {
                throw ValidationException::withMessages(["samples.{$index}.sample_type_uuid" => 'Choisissez un type de prélèvement actif.']);
            }

            $tube = filled($line['tube_type_uuid'] ?? null)
                ? LabTubeType::query()->where('uuid', $line['tube_type_uuid'])->where('is_active', true)->first()
                : ($type->tubeType?->trashed() ? null : $type->tubeType);
            if (filled($line['tube_type_uuid'] ?? null) && $tube === null) {
                throw ValidationException::withMessages(["samples.{$index}.tube_type_uuid" => 'Choisissez un type de tube actif.']);
            }

            $quantity = (int) ($line['quantity'] ?? 1);
            if ($quantity < 1 || $quantity > 10) {
                throw ValidationException::withMessages(["samples.{$index}.quantity" => 'De 1 à 10 tubes par ligne.']);
            }

            for ($copy = 0; $copy < $quantity; $copy++) {
                $expanded[] = [$type, $tube, filled($line['notes'] ?? null) ? mb_substr(trim((string) $line['notes']), 0, 500) : null];
            }
        }

        if ($expanded === []) {
            throw ValidationException::withMessages(['samples' => 'Ajoutez au moins un prélèvement.']);
        }
        if (count($expanded) > self::MAX_PER_GESTURE) {
            throw ValidationException::withMessages(['samples' => self::MAX_PER_GESTURE.' tubes au plus en une fois.']);
        }

        $sequence = (int) LabSample::query()->where('lab_request_id', $request->id)->max('sequence');

        return collect($expanded)->map(function (array $row) use ($request, $actor, &$sequence): LabSample {
            [$type, $tube, $notes] = $row;
            $sequence++;

            return LabSample::query()->create([
                'lab_request_id' => $request->id,
                'sample_type_id' => $type->id,
                'tube_type_id' => $tube?->id,
                'sample_type_name_snapshot' => $type->name,
                'tube_code_snapshot' => $tube?->code,
                'tube_name_snapshot' => $tube?->name,
                'tube_color_snapshot' => $tube?->cap_color,
                'tube_color_hex_snapshot' => $tube?->color_hex,
                'sequence' => $sequence,
                'barcode' => "{$request->lab_number}-{$sequence}",
                'notes' => $notes,
                'collected_at' => now(),
                'collected_by' => $actor->getKey(),
            ]);
        });
    }
}
