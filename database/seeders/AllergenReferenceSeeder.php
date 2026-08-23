<?php

namespace Database\Seeders;

use App\Enums\AllergenCategory;
use App\Models\AllergenReference;
use Illuminate\Database\Seeder;

class AllergenReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $references = [
            ['code' => 'PENICILLINS', 'name' => 'Pénicillines', 'category' => AllergenCategory::Medication],
            ['code' => 'CEPHALOSPORINS', 'name' => 'Céphalosporines', 'category' => AllergenCategory::Medication],
            ['code' => 'SULFONAMIDES', 'name' => 'Sulfamides antibactériens', 'category' => AllergenCategory::Medication],
            ['code' => 'ASPIRIN', 'name' => 'Aspirine', 'category' => AllergenCategory::Medication],
            ['code' => 'NSAIDS', 'name' => 'Anti-inflammatoires non stéroïdiens (AINS)', 'category' => AllergenCategory::Medication],
            ['code' => 'LATEX', 'name' => 'Latex', 'category' => AllergenCategory::Material],
            ['code' => 'MILK', 'name' => 'Lait', 'category' => AllergenCategory::Food],
            ['code' => 'EGGS', 'name' => 'Œufs', 'category' => AllergenCategory::Food],
            ['code' => 'FISH', 'name' => 'Poissons', 'category' => AllergenCategory::Food],
            ['code' => 'CRUSTACEAN_SHELLFISH', 'name' => 'Crustacés', 'category' => AllergenCategory::Food],
            ['code' => 'TREE_NUTS', 'name' => 'Fruits à coque', 'category' => AllergenCategory::Food],
            ['code' => 'PEANUTS', 'name' => 'Arachides', 'category' => AllergenCategory::Food],
            ['code' => 'WHEAT', 'name' => 'Blé', 'category' => AllergenCategory::Food],
            ['code' => 'SOY', 'name' => 'Soja', 'category' => AllergenCategory::Food],
            ['code' => 'SESAME', 'name' => 'Sésame', 'category' => AllergenCategory::Food],
        ];

        foreach ($references as $reference) {
            AllergenReference::query()->updateOrCreate(
                ['code' => $reference['code']],
                [
                    'name' => $reference['name'],
                    'category' => $reference['category'],
                    'active' => true,
                ],
            );
        }
    }
}
