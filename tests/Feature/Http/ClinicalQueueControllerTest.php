<?php

namespace Tests\Feature\Http;

use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalQueueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(string $number = 'M-000001'): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_normal_patient_is_hidden_from_medicine_until_care_completes_the_handoff(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.update', 'care.complete']);
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $care = $episode->orientations->sole('destination_module', CatalogModule::Care);

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->component('Care/Index')
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Index')
                ->has('orientations.data', 0));

        $this->actingAs($nurse)
            ->post("/care/orientations/{$care->uuid}/accept")
            ->assertRedirect();
        $this->actingAs($nurse)
            ->post("/care/orientations/{$care->uuid}/complete")
            ->assertRedirect();

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Index')
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid)
                ->where('orientations.data.0.source_module', CatalogModule::Care->value));
    }

    public function test_emergency_patient_is_visible_in_both_queues_immediately(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);
        $doctor = $this->user('MEDICINE', ['consultations.view']);
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));
        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));
    }

    public function test_queue_routes_enforce_their_own_permissions(): void
    {
        $unauthorized = $this->user('PHARMACY', []);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $care = $episode->orientations->sole();

        $this->actingAs($unauthorized)->get('/care')->assertForbidden();
        $this->actingAs($unauthorized)->get('/medicine')->assertForbidden();
        $this->actingAs($unauthorized)->post("/care/orientations/{$care->uuid}/accept")->assertForbidden();
    }
}
