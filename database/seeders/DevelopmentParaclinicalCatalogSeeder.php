<?php

namespace Database\Seeders;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentParaclinicalCatalogSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, description: string}> */
    private const IMAGING = [
        ['code' => 'ECG', 'name' => 'Électrocardiogramme (ECG)', 'description' => 'ECG standard 12 dérivations au repos.'],
        ['code' => 'ECG-EFFORT', 'name' => 'Électrocardiogramme d’effort', 'description' => 'Enregistrement électrocardiographique pendant un effort contrôlé.'],
        ['code' => 'HOLTER-ECG', 'name' => 'Holter ECG', 'description' => 'Enregistrement ambulatoire prolongé du rythme cardiaque.'],
        ['code' => 'ECHO-CARD', 'name' => 'Échocardiographie', 'description' => 'Exploration échographique du cœur.'],
        ['code' => 'ECHO-ABD', 'name' => 'Échographie abdominale', 'description' => 'Exploration échographique abdominale.'],
        ['code' => 'ECHO-ABD-PEL', 'name' => 'Échographie abdomino-pelvienne', 'description' => 'Exploration échographique abdominale et pelvienne.'],
        ['code' => 'ECHO-PEL', 'name' => 'Échographie pelvienne', 'description' => 'Exploration échographique pelvienne.'],
        ['code' => 'ECHO-OBS', 'name' => 'Échographie obstétricale', 'description' => 'Échographie de suivi de grossesse.'],
        ['code' => 'ECHO-OBS-T1', 'name' => 'Échographie obstétricale du 1er trimestre', 'description' => 'Évaluation échographique du premier trimestre.'],
        ['code' => 'ECHO-OBS-T2', 'name' => 'Échographie obstétricale du 2e trimestre', 'description' => 'Évaluation échographique du deuxième trimestre.'],
        ['code' => 'ECHO-OBS-T3', 'name' => 'Échographie obstétricale du 3e trimestre', 'description' => 'Évaluation échographique du troisième trimestre.'],
        ['code' => 'ECHO-MORPHO', 'name' => 'Échographie morphologique fœtale', 'description' => 'Étude morphologique échographique du fœtus.'],
        ['code' => 'ECHO-RENAL', 'name' => 'Échographie rénale et vésicale', 'description' => 'Exploration des reins et de la vessie.'],
        ['code' => 'ECHO-PROSTATE', 'name' => 'Échographie prostatique', 'description' => 'Exploration échographique de la prostate.'],
        ['code' => 'ECHO-THYROIDE', 'name' => 'Échographie thyroïdienne', 'description' => 'Exploration échographique de la thyroïde.'],
        ['code' => 'ECHO-MAMMAIRE', 'name' => 'Échographie mammaire', 'description' => 'Exploration échographique mammaire.'],
        ['code' => 'ECHO-SCROTALE', 'name' => 'Échographie scrotale', 'description' => 'Exploration échographique scrotale et testiculaire.'],
        ['code' => 'ECHO-PARTIES-MOLLES', 'name' => 'Échographie des parties molles', 'description' => 'Exploration échographique ciblée des tissus mous.'],
        ['code' => 'DOPPLER-MI-ART', 'name' => 'Écho-Doppler artériel des membres inférieurs', 'description' => 'Exploration Doppler artérielle des membres inférieurs.'],
        ['code' => 'DOPPLER-MI-VEIN', 'name' => 'Écho-Doppler veineux des membres inférieurs', 'description' => 'Exploration Doppler veineuse des membres inférieurs.'],
    ];

    /** @var array<int, array{code: string, name: string, description: string}> */
    private const LABORATORY = [
        ['code' => 'LAB-NFS', 'name' => 'Numération formule sanguine (NFS)', 'description' => 'Exploration hématologique complète.'],
        ['code' => 'LAB-GLYC', 'name' => 'Glycémie', 'description' => 'Dosage du glucose sanguin.'],
        ['code' => 'LAB-GROUP-RH', 'name' => 'Groupage sanguin et rhésus', 'description' => 'Détermination ABO et facteur Rhésus.'],
        ['code' => 'LAB-TDR-PALU', 'name' => 'Test rapide du paludisme', 'description' => 'Recherche antigénique rapide du paludisme.'],
        ['code' => 'LAB-CRP', 'name' => 'Protéine C-réactive (CRP)', 'description' => 'Marqueur biologique inflammatoire.'],
        ['code' => 'LAB-CREAT', 'name' => 'Créatininémie', 'description' => 'Dosage sanguin de la créatinine.'],
        ['code' => 'LAB-UREE', 'name' => 'Urée sanguine', 'description' => 'Dosage sanguin de l’urée.'],
        ['code' => 'LAB-ASAT', 'name' => 'ASAT (TGO)', 'description' => 'Dosage de l’aspartate aminotransférase.'],
        ['code' => 'LAB-ALAT', 'name' => 'ALAT (TGP)', 'description' => 'Dosage de l’alanine aminotransférase.'],
        ['code' => 'LAB-BILI-T', 'name' => 'Bilirubine totale', 'description' => 'Dosage de la bilirubine totale.'],
        ['code' => 'LAB-CHOL-T', 'name' => 'Cholestérol total', 'description' => 'Dosage du cholestérol total.'],
        ['code' => 'LAB-HDL', 'name' => 'Cholestérol HDL', 'description' => 'Dosage du cholestérol HDL.'],
        ['code' => 'LAB-LDL', 'name' => 'Cholestérol LDL', 'description' => 'Dosage du cholestérol LDL.'],
        ['code' => 'LAB-TG', 'name' => 'Triglycérides', 'description' => 'Dosage sanguin des triglycérides.'],
        ['code' => 'LAB-NA', 'name' => 'Natrémie', 'description' => 'Dosage sanguin du sodium.'],
        ['code' => 'LAB-K', 'name' => 'Kaliémie', 'description' => 'Dosage sanguin du potassium.'],
        ['code' => 'LAB-HCG', 'name' => 'Test de grossesse β-hCG', 'description' => 'Recherche ou dosage de la β-hCG.'],
        ['code' => 'LAB-ECBU', 'name' => 'Examen cytobactériologique des urines (ECBU)', 'description' => 'Analyse cytologique et bactériologique des urines.'],
        ['code' => 'LAB-BK', 'name' => 'Recherche de BAAR / BK', 'description' => 'Recherche de bacilles acido-alcoolo-résistants.'],
        ['code' => 'LAB-VIH', 'name' => 'Sérologie VIH', 'description' => 'Dépistage sérologique du VIH selon protocole.'],
        ['code' => 'LAB-AGHBS', 'name' => 'Antigène HBs', 'description' => 'Recherche de l’antigène HBs.'],
        ['code' => 'LAB-SYPHILIS', 'name' => 'Sérologie syphilitique', 'description' => 'Dépistage sérologique de la syphilis.'],
    ];

    /** @var array<int, array<string, mixed>> */
    private const DEFINITIONS = [
        ['service' => 'LAB-NFS', 'code' => 'NFS', 'level' => 'PARENT', 'designation' => 'Numération formule sanguine', 'type' => 'TEXT', 'order' => 10],
        ['service' => 'LAB-NFS', 'code' => 'NFS-HB', 'level' => 'CHILD', 'parent' => 'NFS', 'designation' => 'Hémoglobine', 'type' => 'NUMERIC', 'male' => '13–17', 'female' => '12–16', 'child_male' => '11–16', 'child_female' => '11–16', 'unit' => 'g/dL', 'order' => 11],
        ['service' => 'LAB-NFS', 'code' => 'NFS-HT', 'level' => 'CHILD', 'parent' => 'NFS', 'designation' => 'Hématocrite', 'type' => 'NUMERIC', 'male' => '40–52', 'female' => '37–47', 'unit' => '%', 'order' => 12],
        ['service' => 'LAB-NFS', 'code' => 'NFS-GB', 'level' => 'CHILD', 'parent' => 'NFS', 'designation' => 'Leucocytes', 'type' => 'NUMERIC', 'general' => '4–10', 'unit' => 'G/L', 'order' => 13],
        ['service' => 'LAB-NFS', 'code' => 'NFS-PLT', 'level' => 'CHILD', 'parent' => 'NFS', 'designation' => 'Plaquettes', 'type' => 'NUMERIC', 'general' => '150–400', 'unit' => 'G/L', 'order' => 14],
        ['service' => 'LAB-GLYC', 'code' => 'GLYC', 'designation' => 'Glycémie', 'type' => 'NUMERIC', 'general' => '0,70–1,10', 'unit' => 'g/L', 'order' => 20],
        ['service' => 'LAB-GROUP-RH', 'code' => 'GROUP-RH', 'designation' => 'Groupe sanguin et Rhésus', 'type' => 'CHOICE', 'values' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 'order' => 30],
        ['service' => 'LAB-TDR-PALU', 'code' => 'TDR-PALU', 'designation' => 'TDR paludisme', 'type' => 'CHOICE', 'general' => 'Négatif', 'values' => ['Négatif', 'Positif', 'Indéterminé'], 'order' => 40],
        ['service' => 'LAB-CRP', 'code' => 'CRP', 'designation' => 'Protéine C-réactive', 'type' => 'NUMERIC', 'general' => '< 6', 'unit' => 'mg/L', 'order' => 50],
        ['service' => 'LAB-CREAT', 'code' => 'CREAT', 'designation' => 'Créatinine', 'type' => 'NUMERIC', 'male' => '62–115', 'female' => '44–97', 'unit' => 'µmol/L', 'order' => 60],
        ['service' => 'LAB-UREE', 'code' => 'UREE', 'designation' => 'Urée', 'type' => 'NUMERIC', 'general' => '2,5–7,5', 'unit' => 'mmol/L', 'order' => 70],
        ['service' => 'LAB-ASAT', 'code' => 'ASAT', 'designation' => 'ASAT (TGO)', 'type' => 'NUMERIC', 'general' => '< 40', 'unit' => 'UI/L', 'order' => 80],
        ['service' => 'LAB-ALAT', 'code' => 'ALAT', 'designation' => 'ALAT (TGP)', 'type' => 'NUMERIC', 'general' => '< 40', 'unit' => 'UI/L', 'order' => 90],
        ['service' => 'LAB-BILI-T', 'code' => 'BILI-T', 'designation' => 'Bilirubine totale', 'type' => 'NUMERIC', 'general' => '3–17', 'unit' => 'µmol/L', 'order' => 100],
        ['service' => 'LAB-CHOL-T', 'code' => 'CHOL-T', 'designation' => 'Cholestérol total', 'type' => 'NUMERIC', 'general' => '< 2,00', 'unit' => 'g/L', 'order' => 110],
        ['service' => 'LAB-HDL', 'code' => 'HDL', 'designation' => 'Cholestérol HDL', 'type' => 'NUMERIC', 'general' => '> 0,40', 'unit' => 'g/L', 'order' => 120],
        ['service' => 'LAB-LDL', 'code' => 'LDL', 'designation' => 'Cholestérol LDL', 'type' => 'NUMERIC', 'general' => 'Selon risque cardiovasculaire', 'unit' => 'g/L', 'order' => 130],
        ['service' => 'LAB-TG', 'code' => 'TG', 'designation' => 'Triglycérides', 'type' => 'NUMERIC', 'general' => '< 1,50', 'unit' => 'g/L', 'order' => 140],
        ['service' => 'LAB-NA', 'code' => 'NA', 'designation' => 'Sodium', 'type' => 'NUMERIC', 'general' => '135–145', 'unit' => 'mmol/L', 'order' => 150],
        ['service' => 'LAB-K', 'code' => 'K', 'designation' => 'Potassium', 'type' => 'NUMERIC', 'general' => '3,5–5,0', 'unit' => 'mmol/L', 'order' => 160],
        ['service' => 'LAB-HCG', 'code' => 'HCG', 'designation' => 'β-hCG', 'type' => 'CHOICE', 'values' => ['Négatif', 'Positif', 'Indéterminé'], 'order' => 170],
        ['service' => 'LAB-ECBU', 'code' => 'ECBU', 'designation' => 'ECBU', 'type' => 'TEXT', 'order' => 180],
        ['service' => 'LAB-BK', 'code' => 'BK', 'designation' => 'Recherche de BAAR / BK', 'type' => 'CHOICE', 'values' => ['Négatif', 'Positif'], 'order' => 190],
        ['service' => 'LAB-VIH', 'code' => 'VIH', 'designation' => 'Sérologie VIH', 'type' => 'CHOICE', 'values' => ['Non réactif', 'Réactif', 'Indéterminé'], 'order' => 200],
        ['service' => 'LAB-AGHBS', 'code' => 'AG-HBS', 'designation' => 'Antigène HBs', 'type' => 'CHOICE', 'values' => ['Négatif', 'Positif'], 'order' => 210],
        ['service' => 'LAB-SYPHILIS', 'code' => 'SYPHILIS', 'designation' => 'Sérologie syphilitique', 'type' => 'CHOICE', 'values' => ['Non réactif', 'Réactif', 'Indéterminé'], 'order' => 220],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentParaclinicalCatalogSeeder est réservé à local/testing.');
        }

        $actor = $this->resolveActor();
        $previous = Auth::guard()->user();
        Auth::guard()->setUser($actor);

        try {
            DB::transaction(function () use ($actor): void {
                foreach (self::IMAGING as $service) {
                    $this->upsertService($service, CatalogModule::Imaging, $actor);
                }
                foreach (self::LABORATORY as $service) {
                    $this->upsertService($service, CatalogModule::Laboratory, $actor);
                }

                foreach (self::DEFINITIONS as $definition) {
                    $catalogItem = CatalogItem::query()->where('code', $definition['service'])->firstOrFail();
                    $parent = isset($definition['parent'])
                        ? AnalysisCatalog::query()->where('code', $definition['parent'])->firstOrFail()
                        : null;
                    $analysis = AnalysisCatalog::withTrashed()->firstOrNew(['code' => $definition['code']]);
                    if ($analysis->trashed()) {
                        throw new RuntimeException("L’analyse {$definition['code']} est archivée ; restaurez-la explicitement.");
                    }
                    if ($analysis->exists) {
                        continue;
                    }
                    $analysis->fill([
                        'catalog_item_id' => $catalogItem->id,
                        'parent_id' => $parent?->id,
                        'level' => $definition['level'] ?? 'NORMAL',
                        'designation' => $definition['designation'],
                        'description' => 'Valeurs indicatives à valider selon la méthode et les réactifs du laboratoire.',
                        'result_type' => $definition['type'],
                        'reference_general' => $definition['general'] ?? null,
                        'reference_male' => $definition['male'] ?? null,
                        'reference_female' => $definition['female'] ?? null,
                        'reference_child_male' => $definition['child_male'] ?? null,
                        'reference_child_female' => $definition['child_female'] ?? null,
                        'unit' => $definition['unit'] ?? null,
                        'predefined_values' => $definition['values'] ?? null,
                        'display_order' => $definition['order'],
                        'is_active' => true,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ])->save();
                }
            });
        } finally {
            $previous ? Auth::guard()->setUser($previous) : Auth::guard()->forgetUser();
        }

        $this->command?->info(count(self::IMAGING).' examens ECG/Échographie et '.count(self::DEFINITIONS).' définitions d’analyse disponibles.');
    }

    /** @param array{code: string, name: string, description: string} $service */
    private function upsertService(array $service, CatalogModule $module, User $actor): void
    {
        $item = CatalogItem::withTrashed()->where('code', $service['code'])->first();
        if ($item?->trashed()) {
            throw new RuntimeException("La prestation {$service['code']} est archivée ; restaurez-la explicitement.");
        }
        if ($item) {
            if ($item->type !== CatalogItemType::Service) {
                throw new RuntimeException("Le code {$service['code']} existe avec un type incompatible.");
            }
            if ($item->module !== $module) {
                $item->update(['module' => $module->value, 'updated_by' => $actor->id]);
            }

            if ($module === CatalogModule::Laboratory
                && ! $item->reception_selectable
                && $item->reception_routing_mode === null) {
                $item->update([
                    'reception_selectable' => true,
                    'reception_routing_mode' => ReceptionRoutingMode::LaboratoryDirect,
                    'updated_by' => $actor->id,
                ]);
            }

            return;
        }

        CatalogItem::query()->create([
            'code' => $service['code'], 'name' => $service['name'],
            'type' => CatalogItemType::Service->value, 'module' => $module->value,
            'unit' => $module === CatalogModule::Imaging ? 'examen' : 'analyse',
            'billable' => true, 'stockable' => false,
            'reception_selectable' => $module === CatalogModule::Laboratory,
            'reception_routing_mode' => $module === CatalogModule::Laboratory
                ? ReceptionRoutingMode::LaboratoryDirect
                : null,
            'clinician_orderable' => true,
            'description' => $service['description'], 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function resolveActor(): User
    {
        $identity = trim((string) config('rivo.seeders.catalog_actor'));
        $query = User::query()->where('active', true)->whereNull('deactivated_at');
        $actor = $identity !== ''
            ? $query->where(fn ($nested) => $nested->where('uuid', $identity)->orWhere('email', $identity))->first()
            : $query->orderBy('id')->get()->first(fn (User $user) => $user->hasPermissionTo('catalog.items.create')
                || $user->hasPermissionTo('analysis_catalog.create'));

        // Provisioning data still needs a real, traceable local actor. A site
        // may legitimately have no Administration account yet; in that case
        // use its active Laboratory account without granting it management
        // permissions. Interactive writes remain permission-protected.
        $actor ??= $identity === ''
            ? User::query()
                ->where('active', true)
                ->whereNull('deactivated_at')
                ->whereHas('role', fn ($role) => $role->where('code', 'LABORATORY'))
                ->orderBy('id')
                ->first()
            : null;

        if (! $actor) {
            throw new RuntimeException('Aucun compte actif autorisé ne peut provisionner le catalogue paraclinique.');
        }

        return $actor;
    }
}
