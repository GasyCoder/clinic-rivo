<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'espace « Demandes d'examens ».
 *
 * La garantie sous test : chaque compteur correspond à des lignes réelles,
 * et le statut est dérivé de la base — jamais d'un compteur d'interface.
 */
class ParaclinicalRequestDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trois vues : ce qui attend, ce qui vient d'arriver, ce qui est rangé.
     *
     * Les compteurs sont comptés en base sur les mêmes faits que la liste :
     * un onglet ne doit jamais annoncer un nombre puis afficher autre chose.
     */
    public function test_it_groups_the_requests_into_three_views_counted_from_the_database(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor, 'NFS');
        $withdrawn = $this->labRequest($orientation, $doctor, 'CRP');

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
                'kind' => 'lab',
                'uuid' => $withdrawn->uuid,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Requests')
                ->where('counts.active', 1)
                ->where('counts.recent', 0)
                ->where('counts.archived', 1)
                // « Active » est la vue par défaut : c'est le travail en
                // cours que le médecin vient chercher, pas l'historique.
                ->where('filters.filter', 'active')
                ->has('requests', 1)
                ->where('requests.0.status', 'REQUESTED'));
    }

    /** Une demande retirée est rangée, jamais perdue. */
    public function test_a_withdrawn_request_moves_to_the_archive(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $withdrawn = $this->labRequest($orientation, $doctor, 'CRP');

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
            'kind' => 'lab',
            'uuid' => $withdrawn->uuid,
        ]);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=archived')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests', 1)
                ->where('requests.0.status', 'CANCELLED'));
    }

    /**
     * La frontière entre « rendu récemment » et « archivé » : un résultat
     * ancien se range, il ne disparaît pas.
     */
    public function test_an_old_result_leaves_the_recent_view_for_the_archive(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor, 'NFS');

        $request->items()->update(['resulted_at' => now()->subDay(), 'resulted_by' => $doctor->id]);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 1));

        $request->items()->update(['resulted_at' => now()->subDays(30)]);

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=recent')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 0));

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=archived')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 1));
    }

    /** La recherche porte sur le patient, son dossier, le passage et l'examen. */
    public function test_it_searches_by_patient_and_exam(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor, 'Ionogramme');

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=active&q=ionogramme')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 1));

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?q=introuvable')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 0));
    }

    /**
     * Retirer, jamais supprimer (ADR-010).
     *
     * `can_withdraw` doit refléter exactement ce que
     * `CancelParaclinicalRequestAction` accepte : afficher le bouton sur une
     * demande que le serveur refusera ensuite ne serait pas une action, mais
     * une promesse non tenue.
     */
    public function test_the_withdraw_action_is_offered_only_when_the_server_would_accept_it(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor, 'NFS');

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.0.can_withdraw', true));

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
                'kind' => 'lab',
                'uuid' => $request->uuid,
            ])
            ->assertSessionHasNoErrors();

        // Retirée une fois, elle ne se retire plus : le bouton disparaît.
        // Elle a aussi quitté « Active » pour l'archive — c'est là qu'on la
        // relit.
        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens?filter=archived')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.status', 'CANCELLED')
                ->where('requests.0.can_withdraw', false));
    }

    /** L'orientation est nécessaire pour construire l'URL des actions. */
    public function test_each_row_carries_the_orientation_that_owns_it(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor, 'NFS');

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.0.orientation_uuid', $orientation->uuid));
    }

    /** Chaque ligne ramène à la consultation qui l'a émise — jamais à une nouvelle. */
    public function test_each_row_links_back_to_the_consultation_that_ordered_it(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor, 'NFS');

        $this->actingAs($doctor)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'requests.0.consultation_url',
                "/medicine/orientations/{$orientation->uuid}/paraclinique",
            ));
    }

    private function labRequest(EpisodeOrientation $orientation, User $doctor, string $name): LabRequest
    {
        $item = CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        return $this->app->make(CreateLabRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        )->fresh('items');
    }

    /**
     * Ouvrir l'écran et voir son contenu sont deux droits distincts.
     *
     * La route n'exigeait que « Voir les demandes d'analyses » : un compte
     * n'ayant que l'imagerie recevait un 403 devant un écran que le
     * contrôleur savait pourtant lui servir. La porte est désormais
     * `paraclinical_requests.view`; les deux permissions existantes
     * continuent de décider, section par section, de ce qui s'affiche.
     */
    public function test_the_screen_opens_without_the_laboratory_permission(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor, 'NFS');

        $radiologist = $this->accountWith([
            'paraclinical_requests.view', 'imaging_orders.view',
        ]);

        $this->actingAs($radiologist)
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.lab', false)
                ->where('can.imaging', true)
                // L'analyse existe, mais ce compte n'a pas le droit de la voir.
                ->has('requests', 0));
    }

    /** Sans la porte, l'écran reste fermé — la liste n'est pas seulement vide. */
    public function test_the_screen_is_refused_without_its_own_permission(): void
    {
        $this->actingAs($this->accountWith(['laboratory_orders.view', 'imaging_orders.view']))
            ->get('/medicine/demandes-examens')
            ->assertForbidden();
    }

    /**
     * Un vide muet se lit « aucune demande », c'est-à-dire du travail
     * terminé. L'écran doit pouvoir dire que c'est un droit qui manque.
     */
    public function test_the_screen_says_when_no_section_is_visible(): void
    {
        $this->actingAs($this->accountWith(['paraclinical_requests.view']))
            ->get('/medicine/demandes-examens')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.lab', false)
                ->where('can.imaging', false));
    }

    /** @param array<int, string> $permissions */
    private function accountWith(array $permissions): User
    {
        $role = Role::query()->create([
            'code' => 'TEST_'.fake()->unique()->numerify('####'),
            'name' => 'Rôle de test',
        ]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);

        foreach ([
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'care.view', 'vitals.view',
            'paraclinical_requests.view',
            'laboratory_orders.create', 'laboratory_orders.view',
            'imaging_orders.create', 'imaging_orders.view',
            'diagnoses.view', 'prescriptions.view',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function medicineConsultation(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
