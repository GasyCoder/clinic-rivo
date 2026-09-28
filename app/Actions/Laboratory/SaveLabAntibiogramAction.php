<?php

namespace App\Actions\Laboratory;

use App\Models\LabAntibiogram;
use App\Models\LabAntibiogramResult;
use App\Models\LabAntibiotic;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — l'antibiogramme d'un germe : une ligne par antibiotique testé,
 * S / I / R et, si elle est lue, la mesure (diamètre en mm). Une ligne laissée
 * sans interprétation n'est pas enregistrée : l'antibiotique n'a pas été testé.
 */
class SaveLabAntibiogramAction
{
    /** @param  array<int, array<string, mixed>>  $lines */
    public function execute(LabRequestItem $item, LabAntibiogram $antibiogram, array $lines, ?string $notes, User $actor): LabAntibiogram
    {
        if ($actor->cannot('laboratory_results.create')) {
            throw new AuthorizationException('La saisie des résultats demande le droit « laboratory_results.create ».');
        }

        return DB::transaction(function () use ($item, $antibiogram, $lines, $notes): LabAntibiogram {
            $locked = LabItemGuard::lockEditable($item);
            if ($antibiogram->lab_request_item_id !== $locked->id) {
                abort(404);
            }

            $antibiogram->loadMissing('bacterium');
            $allowed = LabAntibiotic::query()
                ->where('family_id', $antibiogram->bacterium?->family_id)
                ->get()
                ->keyBy('uuid');

            $kept = [];
            foreach (array_values($lines) as $index => $line) {
                $interpretation = $line['interpretation'] ?? null;
                if ($interpretation === null || $interpretation === '') {
                    continue;
                }

                $antibiotic = $allowed->get($line['antibiotic_uuid'] ?? '');
                if ($antibiotic === null) {
                    throw ValidationException::withMessages(["lines.{$index}.antibiotic_uuid" => 'Cet antibiotique n’est pas testé pour la famille de ce germe.']);
                }

                LabAntibiogramResult::query()->updateOrCreate(
                    ['lab_antibiogram_id' => $antibiogram->id, 'antibiotic_id' => $antibiotic->id],
                    [
                        'antibiotic_name_snapshot' => $antibiotic->name,
                        'interpretation' => $interpretation,
                        'measure' => $line['measure'] ?? null,
                        'measure_unit' => filled($line['measure_unit'] ?? null) ? $line['measure_unit'] : 'mm',
                    ],
                );
                $kept[] = $antibiotic->id;
            }

            $antibiogram->results()->whereNotIn('antibiotic_id', $kept)->get()->each->delete();
            $antibiogram->update(['notes' => filled($notes) ? trim($notes) : null]);

            return $antibiogram->fresh('results');
        });
    }
}
