<?php

namespace Tests\Feature\Hospitalization;

use App\Enums\HospitalCareLevel;
use App\Enums\HospitalStayStatus;
use App\Models\HospitalBed;
use App\Models\HospitalRoom;
use App\Models\HospitalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Hospitalization\Concerns\AdmitsHospitalizedPatients;
use Tests\TestCase;

/**
 * ADR-164 — un lit occupé ne peut être attribué à un autre patient.
 *
 * Tant que le site n'a configuré aucun lit, la chambre reste saisie à la main
 * (ADR-161). Dès le premier lit, on choisit un lit libre : le service et le
 * niveau de soins en découlent, et le lit se libère à la fin du séjour.
 */
class HospitalBedAssignmentTest extends TestCase
{
    use AdmitsHospitalizedPatients, RefreshDatabase;

    public function test_the_first_bed_completes_the_admission_without_a_second_placement(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        [$bed] = $this->room('Réanimation', HospitalCareLevel::Intensive, 'Box', 2);

        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('bedsConfigured', true)
                ->where('stay.bed_uuid', null)
                ->has('freeBeds.0.rooms.0.beds', 2));

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['hospital_bed_uuid' => $bed->uuid])
            ->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame($bed->id, $stay->hospital_bed_id);
        $this->assertSame('BED_'.$bed->id, $stay->bed_active_key);
        $this->assertSame('Réanimation', $stay->service);
        $this->assertSame('Box · Lit 1', $stay->room_bed);

        // Compléter l'admission n'est pas une mutation : un seul emplacement,
        // et son niveau de soins suit le service du lit.
        $movement = $stay->movements()->sole();
        $this->assertSame($bed->id, $movement->hospital_bed_id);
        $this->assertSame(HospitalCareLevel::Intensive, $movement->care_level);
        $this->assertSame($doctor->id, $movement->updated_by);
    }

    public function test_an_occupied_bed_is_refused_to_another_patient_and_names_who_occupies_it(): void
    {
        $doctor = $this->hospitalDoctor();
        $first = $this->admitted($doctor, 'Rakoto', 'Soa');
        $second = $this->admitted($doctor, 'Rabe', 'Hery');
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 12', 2);

        $this->actingAs($doctor)->put("/hospitalisation/{$first->uuid}", ['hospital_bed_uuid' => $bed->uuid])
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)->put("/hospitalisation/{$second->uuid}", ['hospital_bed_uuid' => $bed->uuid])
            ->assertSessionHasErrors(['hospital_bed_uuid' => 'Chambre 12 · Lit 1 est déjà occupé par RAKOTO Soa : choisissez un lit libre.']);

        $this->assertNull($second->fresh()->hospital_bed_id);

        // Le lit occupé n'est plus proposé.
        $this->actingAs($doctor)->get("/hospitalisation/{$second->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('freeBeds.0.rooms.0.beds', 1)
                ->where('freeBeds.0.rooms.0.beds.0.label', 'Lit 2'));
    }

    public function test_the_database_itself_refuses_two_ongoing_stays_in_one_bed(): void
    {
        $doctor = $this->hospitalDoctor();
        $first = $this->admitted($doctor);
        $second = $this->admitted($doctor, 'Rabe', 'Hery');
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 1', 1);

        $first->update(['hospital_bed_id' => $bed->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        $second->update(['hospital_bed_id' => $bed->id]);
    }

    public function test_a_bed_out_of_service_is_refused(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 3', 1);
        $bed->update(['out_of_service_at' => now(), 'out_of_service_reason' => 'Sommier cassé']);

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['hospital_bed_uuid' => $bed->uuid])
            ->assertSessionHasErrors(['hospital_bed_uuid' => 'Chambre 3 · Lit 1 est hors service : choisissez un autre lit.']);
    }

    public function test_changing_bed_opens_a_new_placement_and_frees_the_old_bed(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        [$standard] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 12', 1);
        [$box] = $this->room('Réanimation', HospitalCareLevel::Intensive, 'Box', 1);

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['hospital_bed_uuid' => $standard->uuid])->assertSessionHasNoErrors();
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/mouvements", [
            'hospital_bed_uuid' => $box->uuid,
            'reason' => 'Choc septique',
        ])->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame($box->id, $stay->hospital_bed_id);
        $this->assertSame('Réanimation', $stay->service);

        $movements = $stay->movements()->get();
        $this->assertCount(2, $movements);
        $this->assertSame($standard->id, $movements[0]->hospital_bed_id);
        $this->assertNotNull($movements[0]->ended_at, 'l’emplacement quitté est fermé, pas écrasé');
        $this->assertSame(HospitalCareLevel::Intensive, $movements[1]->care_level);

        // Le lit quitté est de nouveau libre : un autre patient peut l'occuper.
        $other = $this->admitted($doctor, 'Rabe', 'Hery');
        $this->actingAs($doctor)->put("/hospitalisation/{$other->uuid}", ['hospital_bed_uuid' => $standard->uuid])->assertSessionHasNoErrors();
    }

    public function test_moving_to_the_same_bed_or_by_free_text_is_refused_once_beds_are_configured(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 12', 1);
        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['hospital_bed_uuid' => $bed->uuid])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/mouvements", ['hospital_bed_uuid' => $bed->uuid])
            ->assertSessionHasErrors('hospital_bed_uuid');

        // Une saisie libre contournerait le contrôle d'occupation : refusée, et dite.
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/mouvements", [
            'service' => 'Réanimation', 'room_bed' => 'Box 2', 'care_level' => 'INTENSIVE',
        ])->assertSessionHasErrors(['hospital_bed_uuid', 'service', 'room_bed', 'care_level']);
        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['room_bed' => 'Chambre 9'])
            ->assertSessionHasErrors(['hospital_bed_uuid', 'room_bed']);

        $this->assertCount(1, $stay->movements()->get());
    }

    public function test_the_end_of_the_stay_frees_the_bed(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 12', 1);
        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['hospital_bed_uuid' => $bed->uuid])->assertSessionHasNoErrors();

        $stay->update(['status' => HospitalStayStatus::Discharged, 'discharged_at' => now(), 'active_key' => null]);

        $stay->refresh();
        $this->assertNull($stay->bed_active_key, 'le lit est libéré');
        $this->assertSame($bed->id, $stay->hospital_bed_id, 'le séjour garde le lit qu’il a occupé');

        $other = $this->admitted($doctor, 'Rabe', 'Hery');
        $this->actingAs($doctor)->put("/hospitalisation/{$other->uuid}", ['hospital_bed_uuid' => $bed->uuid])->assertSessionHasNoErrors();
    }

    public function test_the_list_shows_the_bed_plan_and_who_still_needs_a_bed(): void
    {
        $doctor = $this->hospitalDoctor();
        $placed = $this->admitted($doctor, 'Rakoto', 'Soa');
        $waiting = $this->admitted($doctor, 'Rabe', 'Hery');
        [$bed] = $this->room('Médecine interne', HospitalCareLevel::Standard, 'Chambre 12', 2);
        $this->actingAs($doctor)->put("/hospitalisation/{$placed->uuid}", ['hospital_bed_uuid' => $bed->uuid])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('beds.summary.beds', 2)
                ->where('beds.summary.occupied', 1)
                ->where('beds.summary.free', 1)
                ->where('beds.services.0.rooms.0.beds.0.state', 'OCCUPIED')
                ->where('beds.services.0.rooms.0.beds.0.occupant.patient', 'RAKOTO Soa')
                ->where('beds.services.0.rooms.0.beds.1.state', 'FREE')
                ->has('beds.unassigned', 1)
                ->where('beds.unassigned.0.stay_uuid', $waiting->uuid)
                ->where('stays.data', fn ($stays) => collect($stays)->firstWhere('uuid', $waiting->uuid)['needs_bed'] === true
                    && collect($stays)->firstWhere('uuid', $placed->uuid)['needs_bed'] === false));
    }

    public function test_without_any_configured_bed_the_room_stays_free_text(): void
    {
        $doctor = $this->hospitalDoctor();
        $stay = $this->admitted($doctor);

        $this->actingAs($doctor)->get('/hospitalisation')->assertInertia(fn (Assert $page) => $page->where('beds', null)->where('bedsConfigured', false));
        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")->assertInertia(fn (Assert $page) => $page->where('bedsConfigured', false));

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", ['room_bed' => 'Chambre 3, lit B'])->assertSessionHasNoErrors();
        $this->assertSame('Chambre 3, lit B', $stay->fresh()->room_bed);
    }

    /** @return list<HospitalBed> */
    private function room(string $service, HospitalCareLevel $level, string $room, int $beds): array
    {
        $serviceModel = HospitalService::query()->firstOrCreate(['name' => $service], ['care_level' => $level]);
        $roomModel = HospitalRoom::query()->create(['hospital_service_id' => $serviceModel->id, 'name' => $room]);

        return collect(range(1, $beds))
            ->map(fn (int $number) => HospitalBed::query()->create(['hospital_room_id' => $roomModel->id, 'label' => "Lit {$number}"]))
            ->all();
    }
}
