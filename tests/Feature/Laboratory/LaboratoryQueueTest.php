<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LaboratoryQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    /**
     * Une demande annulée par le médecin quitte la paillasse.
     *
     * L'ADR-079 la retire de l'écran Paraclinique et cesse de la compter
     * comme demande en attente — mais la file du Laboratoire la listait
     * encore : un technicien pouvait passer une analyse que le prescripteur
     * avait annulée.
     */
    public function test_a_cancelled_request_leaves_the_laboratory_bench(): void
    {
        $technician = $this->technician();
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $analysis = $this->analysis($technician);

        $live = $this->labRequest($episode, $orientation, $technician);
        LabRequestItem::query()->create([
            'lab_request_id' => $live->id, 'catalog_item_id' => $analysis->id,
            'catalog_item_uuid' => $analysis->uuid,
            'catalog_item_name_snapshot' => 'NFS demandee', 'catalog_item_code_snapshot' => 'LAB-NFS',
        ]);

        $cancelled = $this->labRequest($episode, $orientation, $technician, cancelled: true);
        LabRequestItem::query()->create([
            'lab_request_id' => $cancelled->id, 'catalog_item_id' => $analysis->id,
            'catalog_item_uuid' => $analysis->uuid,
            'catalog_item_name_snapshot' => 'NFS retiree', 'catalog_item_code_snapshot' => 'LAB-NFS',
        ]);

        $this->actingAs($technician)->get('/laboratory')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.pending', 1)
                ->where('counts.all', 1)
                ->count('items.data', 1)
                ->where('items.data.0.name', 'NFS demandee'));
    }

    /** Les compteurs comptent toute la file, jamais la seule page affichée. */
    public function test_the_counters_cover_the_whole_queue(): void
    {
        $technician = $this->technician();
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $analysis = $this->analysis($technician);
        $request = $this->labRequest($episode, $orientation, $technician);

        // Une page en affiche 20 : au-delà, un compte tiré de la page mentirait.
        foreach (range(1, 22) as $index) {
            LabRequestItem::query()->create([
                'lab_request_id' => $request->id, 'catalog_item_id' => $analysis->id,
                'catalog_item_uuid' => $analysis->uuid,
                'catalog_item_name_snapshot' => "Analyse {$index}", 'catalog_item_code_snapshot' => 'LAB-NFS',
                'resulted_at' => $index <= 2 ? now() : null,
                'result_value' => $index <= 2 ? 'Normal' : null,
            ]);
        }

        $this->actingAs($technician)->get('/laboratory')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.all', 22)
                ->where('counts.pending', 20)
                ->where('counts.resulted', 2)
                ->where('counts.resulted_today', 2)
                ->count('items.data', 20));
    }

    private function technician(): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->where('code', 'LABORATORY')->value('id')]);
        $user->permissions()->syncWithoutDetaching([
            Permission::query()->firstOrCreate(['name' => 'laboratory.view'])->id => ['effect' => 'allow'],
        ]);

        return $user->fresh(['role']);
    }

    /** @return array{0: Episode, 1: \App\Models\EpisodeOrientation} */
    private function episodeWithLabOrientation(User $actor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-8001', 'first_name' => 'Soa', 'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id, 'episode_number' => 'A-26-8001-01',
            'status' => 'OPEN', 'priority' => 'NORMAL', 'started_at' => now(),
            'administrative_status' => 'IN_CARE', 'created_by' => $actor->id,
        ]);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Medicine, CatalogModule::Laboratory, $actor, 'Bilan',
        );

        return [$episode, $orientation];
    }

    private function analysis(User $actor): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => 'LAB-NFS', 'name' => 'NFS', 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory, 'unit' => 'analyse',
            'billable' => true, 'stockable' => false, 'reception_selectable' => false,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function labRequest(Episode $episode, $orientation, User $actor, bool $cancelled = false): LabRequest
    {
        return LabRequest::query()->create([
            'episode_id' => $episode->id, 'lab_orientation_id' => $orientation->id,
            'requested_by' => $actor->id, 'requested_at' => now(),
            ...($cancelled ? [
                'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => 'Plus nécessaire',
            ] : []),
        ]);
    }
}
