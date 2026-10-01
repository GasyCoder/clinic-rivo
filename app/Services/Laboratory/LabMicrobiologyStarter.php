<?php

namespace App\Services\Laboratory;

use App\Models\LabAntibiotic;
use App\Models\LabBacterium;
use App\Models\LabBacteriumFamily;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-213 — le référentiel de microbiologie de départ : les familles, germes et
 * antibiotiques que le laboratoire de la clinique utilisait déjà (labo-vuejs,
 * base ctb-cover), exportés une fois dans `database/seeders/data/lab_microbiology.json`.
 *
 * Rejouable : une entrée déjà présente (archives comprises) n'est jamais
 * recréée ni réécrite — une correction faite dans RIVO l'emporte toujours.
 */
class LabMicrobiologyStarter
{
    public const FIXTURE = 'database/seeders/data/lab_microbiology.json';

    /** @return array{families: int, bacteria: int, antibiotics: int} ce qui a été ajouté */
    public function import(?User $actor = null): array
    {
        $data = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        $added = ['families' => 0, 'bacteria' => 0, 'antibiotics' => 0];
        $author = $actor?->getKey();

        DB::transaction(function () use ($data, &$added, $author): void {
            foreach ($data['families'] ?? [] as $row) {
                $family = LabBacteriumFamily::withTrashed()
                    ->where('normalized_name', LabBacteriumFamily::normalize($row['name']))
                    ->first();

                if ($family === null) {
                    $family = LabBacteriumFamily::create(['name' => $row['name'], 'is_active' => true, 'created_by' => $author]);
                    $added['families']++;
                }

                foreach ($row['bacteria'] ?? [] as $name) {
                    $exists = LabBacterium::withTrashed()->where('family_id', $family->id)
                        ->where('normalized_name', LabBacteriumFamily::normalize($name))->exists();
                    if (! $exists) {
                        LabBacterium::create(['family_id' => $family->id, 'name' => $name, 'is_active' => true, 'created_by' => $author]);
                        $added['bacteria']++;
                    }
                }

                foreach ($row['antibiotics'] ?? [] as $antibiotic) {
                    $exists = LabAntibiotic::withTrashed()->where('family_id', $family->id)
                        ->where('normalized_name', LabBacteriumFamily::normalize($antibiotic['name']))->exists();
                    if (! $exists) {
                        LabAntibiotic::create([
                            'family_id' => $family->id,
                            'name' => $antibiotic['name'],
                            'comment' => $antibiotic['comment'] ?? null,
                            'is_active' => true,
                            'created_by' => $author,
                        ]);
                        $added['antibiotics']++;
                    }
                }
            }
        });

        return $added;
    }
}
