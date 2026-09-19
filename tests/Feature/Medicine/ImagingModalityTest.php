<?php

namespace Tests\Feature\Medicine;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ImagingModality;
use App\Models\CatalogItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentParaclinicalCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-106 — la famille d'un examen d'imagerie est une donnée du catalogue,
 * jamais une déduction sur son code ou son libellé.
 */
class ImagingModalityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les trois cas qui condamnaient la déduction par préfixe : un Holter
     * est cardiologique sans commencer par `ECG-`, et les deux Doppler sont
     * des échographies sans commencer par `ECHO-`.
     */
    public function test_the_catalog_classifies_the_exams_no_prefix_could_reach(): void
    {
        // Le seeder exige un auteur traçable du catalogue (ADR-024).
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
        // Un compte SUPER_ADMIN ne reçoit aucune permission sur un site
        // clinique (ADR-027) : l'auteur du catalogue est un compte local
        // avec l'autorisation explicite.
        $author = User::factory()->create([
            'role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id'),
        ]);
        $author->permissions()->attach(
            Permission::query()->where('name', 'catalog.items.create')->value('id'),
            ['effect' => 'allow'],
        );
        $this->seed(DevelopmentParaclinicalCatalogSeeder::class);

        $modalityOf = fn (string $code) => CatalogItem::query()
            ->where('code', $code)
            ->value('imaging_modality');

        $this->assertSame(ImagingModality::Cardiology, $modalityOf('HOLTER-ECG'));
        $this->assertSame(ImagingModality::Ultrasound, $modalityOf('DOPPLER-MI-ART'));
        $this->assertSame(ImagingModality::Ultrasound, $modalityOf('DOPPLER-MI-VEIN'));
        $this->assertSame(ImagingModality::Cardiology, $modalityOf('ECG'));
        $this->assertSame(ImagingModality::Ultrasound, $modalityOf('ECHO-ABD'));
    }

    /**
     * Un examen ajouté après coup n'est pas classé, et ne doit pas l'être
     * par défaut : le ranger d'office le ferait disparaître d'un onglet
     * sans que personne ne l'ait décidé.
     */
    public function test_a_new_imaging_exam_is_unclassified_until_someone_says_otherwise(): void
    {
        $item = CatalogItem::query()->create([
            'code' => 'IRM-CEREBRALE',
            'name' => 'IRM cérébrale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'created_by' => User::factory()->create()->id,
        ]);

        $this->assertNull($item->fresh()->imaging_modality);
    }
}
