<?php

namespace App\Services\Laboratory;

use App\Models\LabBacteriumFamily;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-214 — les types de tube et de prélèvement de départ, repris du
 * laboratoire de la clinique (labo-vuejs) sans leurs prix. Rejouable : une
 * entrée déjà présente (archives comprises) n'est jamais recréée ni réécrite.
 */
class LabSampleStarter
{
    public const FIXTURE = 'database/seeders/data/lab_samples.json';

    /** @return array{tubes: int, samples: int} ce qui a été ajouté */
    public function import(?User $actor = null): array
    {
        $data = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        $added = ['tubes' => 0, 'samples' => 0];
        $author = $actor?->getKey();

        DB::transaction(function () use ($data, &$added, $author): void {
            $tubes = [];
            foreach ($data['tubes'] ?? [] as $row) {
                $tube = LabTubeType::withTrashed()->where('normalized_code', LabBacteriumFamily::normalize($row['code']))->first();
                if ($tube === null) {
                    $tube = LabTubeType::create([
                        'code' => $row['code'], 'name' => $row['name'], 'cap_color' => $row['cap_color'] ?? null,
                        'color_hex' => $row['color_hex'] ?? null, 'is_active' => true, 'created_by' => $author,
                    ]);
                    $added['tubes']++;
                }
                $tubes[$row['code']] = $tube;
            }

            foreach ($data['samples'] ?? [] as $row) {
                $exists = LabSampleType::withTrashed()->where('normalized_name', LabBacteriumFamily::normalize($row['name']))->exists();
                if (! $exists) {
                    LabSampleType::create([
                        'name' => $row['name'],
                        'tube_type_id' => isset($row['tube']) ? ($tubes[$row['tube']]->id ?? null) : null,
                        'is_active' => true,
                        'created_by' => $author,
                    ]);
                    $added['samples']++;
                }
            }
        });

        return $added;
    }
}
