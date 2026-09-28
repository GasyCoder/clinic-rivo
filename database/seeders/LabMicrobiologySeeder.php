<?php

namespace Database\Seeders;

use App\Services\Laboratory\LabMicrobiologyStarter;
use Illuminate\Database\Seeder;

/** ADR-213 — le référentiel de microbiologie de départ ; rejouable sans rien réécrire. */
class LabMicrobiologySeeder extends Seeder
{
    public function run(LabMicrobiologyStarter $starter): void
    {
        $starter->import();
    }
}
