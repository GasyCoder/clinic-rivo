<?php

namespace Database\Seeders;

use App\Models\DiagnosticCatalog;
use Illuminate\Database\Seeder;
use LogicException;

class DevelopmentDiagnosticCatalogSeeder extends Seeder
{
    /**
     * Jeu initial local destiné à rendre l'autocomplete immédiatement testable.
     * Les codes restent volontairement nuls : aucune codification CIM ne doit
     * être présentée comme validée sans référentiel officiellement fourni.
     *
     * @var array<int, array{name: string, category: string}>
     */
    public const DIAGNOSTICS = [
        ['name' => 'Paludisme simple', 'category' => 'Infectiologie'],
        ['name' => 'Paludisme grave', 'category' => 'Infectiologie'],
        ['name' => 'Fièvre typhoïde', 'category' => 'Infectiologie'],
        ['name' => 'Infection respiratoire aiguë', 'category' => 'Pneumologie'],
        ['name' => 'Pneumonie', 'category' => 'Pneumologie'],
        ['name' => 'Bronchite aiguë', 'category' => 'Pneumologie'],
        ['name' => 'Crise d’asthme', 'category' => 'Pneumologie'],
        ['name' => 'Gastro-entérite aiguë', 'category' => 'Gastro-entérologie'],
        ['name' => 'Gastrite', 'category' => 'Gastro-entérologie'],
        ['name' => 'Reflux gastro-œsophagien', 'category' => 'Gastro-entérologie'],
        ['name' => 'Appendicite aiguë', 'category' => 'Gastro-entérologie'],
        ['name' => 'Déshydratation', 'category' => 'Médecine générale'],
        ['name' => 'Hypertension artérielle', 'category' => 'Cardiologie'],
        ['name' => 'Insuffisance cardiaque', 'category' => 'Cardiologie'],
        ['name' => 'Diabète de type 2', 'category' => 'Endocrinologie'],
        ['name' => 'Hypoglycémie', 'category' => 'Endocrinologie'],
        ['name' => 'Infection urinaire', 'category' => 'Urologie'],
        ['name' => 'Pyélonéphrite aiguë', 'category' => 'Urologie'],
        ['name' => 'Migraine', 'category' => 'Neurologie'],
        ['name' => 'Accident vasculaire cérébral', 'category' => 'Neurologie'],
        ['name' => 'Crise convulsive', 'category' => 'Neurologie'],
        ['name' => 'Otite moyenne aiguë', 'category' => 'ORL'],
        ['name' => 'Angine aiguë', 'category' => 'ORL'],
        ['name' => 'Dermatite allergique', 'category' => 'Dermatologie'],
        ['name' => 'Plaie traumatique', 'category' => 'Traumatologie'],
        ['name' => 'Fracture', 'category' => 'Traumatologie'],
        ['name' => 'Traumatisme crânien', 'category' => 'Traumatologie'],
        ['name' => 'Pré-éclampsie', 'category' => 'Gynécologie-obstétrique'],
        ['name' => 'Anémie', 'category' => 'Hématologie'],
        ['name' => 'Lombalgie aiguë', 'category' => 'Rhumatologie'],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException(
                'DevelopmentDiagnosticCatalogSeeder est strictement interdit hors des environnements local et testing.',
            );
        }

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException(
                'Le catalogue local des diagnostics doit être seedé dans une base de site clinique.',
            );
        }

        foreach (self::DIAGNOSTICS as $diagnostic) {
            $exists = DiagnosticCatalog::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($diagnostic['name'])])
                ->exists();

            if ($exists) {
                continue;
            }

            DiagnosticCatalog::query()->create([
                'code' => null,
                'name' => $diagnostic['name'],
                'category' => $diagnostic['category'],
                'description' => null,
                'is_active' => true,
            ]);
        }
    }
}
