<?php

namespace Database\Seeders;

use App\Services\Laboratory\LabSampleStarter;
use Illuminate\Database\Seeder;

/** ADR-214 — les types de tube et de prélèvement de départ ; rejouable sans rien réécrire. */
class LabSampleSeeder extends Seeder
{
    public function run(LabSampleStarter $starter): void
    {
        $starter->import();
    }
}
