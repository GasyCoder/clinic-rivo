<?php

namespace Tests\Feature\Care;

use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-124 — la file Soins ne montre que les patients à prendre aux Soins et
 * ceux orientés qui attendent encore le médecin.
 */
class CareQueueTabsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function nurse(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'NURSE']);
        // ADR-157 — la file appartient à qui fait les soins (`care.create`).
        foreach (['care.view', 'care.create'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /**
     * ADR-157 — un compte Médecine voyait la file des infirmières. Il détient
     * `care.update` pour corriger une fiche depuis sa consultation (ADR-093),
     * et l'entrée de menu comme la route s'y adossaient : le droit de corriger
     * devenait un droit d'entrer dans l'espace de l'autre métier.
     */
    public function test_the_nurses_queue_is_not_opened_by_the_right_to_correct_a_record(): void
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE']);

        foreach (['care.view', 'care.update', 'vitals.view', 'vitals.update'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        $doctor = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($doctor)->get('/care')->assertForbidden();

        // Le soignant, lui, y entre : c'est lui qui ouvre les fiches.
        $this->actingAs($this->nurse())->get('/care')->assertOk();
    }

    private function episode(string $last): Episode
    {
        $this->sequence++;
        $patient = Patient::create([
            'patient_number' => sprintf('A-26-%04d', $this->sequence),
            'first_name' => 'Jean',
            'last_name' => $last,
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => sprintf('%s-01', $patient->patient_number),
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => CarbonImmutable::parse('2026-09-19 08:00'),
        ]);
    }

    private function orient(Episode $episode, string $module, string $status, string $at = '2026-09-19 09:00', ?User $by = null): EpisodeOrientation
    {
        $active = in_array($status, ['PENDING', 'IN_PROGRESS'], true);

        return EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'destination_module' => $module,
            'status' => $status,
            'active_key' => $active ? "{$episode->id}:{$module}" : null,
            'oriented_by' => User::factory()->create()->id,
            'oriented_at' => CarbonImmutable::parse($at),
            'accepted_by' => $by?->id,
            'accepted_at' => $by ? CarbonImmutable::parse($at)->addMinutes(5) : null,
            'completed_at' => $status === 'COMPLETED' ? CarbonImmutable::parse($at)->addMinutes(20) : null,
        ]);
    }

    private function names(array $props): array
    {
        return collect($props['orientations']['data'])->map(fn ($row) => $row['episode']['patient']['last_name'])->sort()->values()->all();
    }

    public function test_the_queue_only_shows_patients_to_take_and_those_waiting_for_the_doctor(): void
    {
        $nurse = $this->nurse();
        $doctor = User::factory()->create(['name' => 'Dr Rakoto']);

        $active = $this->episode('EnSoins');
        $this->orient($active, 'CARE', 'IN_PROGRESS', by: $nurse);

        $waiting = $this->episode('AttendMedecin');
        $this->orient($waiting, 'CARE', 'COMPLETED');
        $this->orient($waiting, 'MEDICINE', 'PENDING');

        $seen = $this->episode('EnConsultation');
        $this->orient($seen, 'CARE', 'COMPLETED');
        $this->orient($seen, 'MEDICINE', 'IN_PROGRESS', by: $doctor);

        $done = $this->episode('ConsultationFinie');
        $this->orient($done, 'CARE', 'COMPLETED');
        $this->orient($done, 'MEDICINE', 'COMPLETED', by: $doctor);

        $noDoctor = $this->episode('SoinsSeuls');
        $this->orient($noDoctor, 'CARE', 'COMPLETED');

        $get = fn (string $filter) => $this->actingAs($nurse)->get("/care?filter={$filter}")->viewData('page')['props'];

        $this->assertSame(['EnSoins'], $this->names($get('active')));
        $this->assertSame(['AttendMedecin'], $this->names($get('waiting_doctor')));
        // Accueillis par le médecin, terminés ou jamais orientés vers un médecin :
        // ils ne sont plus dans cette file, ils vivent dans le module Patients.
        $this->assertSame(['active' => 1, 'waiting_doctor' => 1], $get('active')['counts']);
        foreach (['with_doctor', 'finished', 'oriented'] as $gone) {
            $this->assertSame(['EnSoins'], $this->names($get($gone)), "« {$gone} » n'est plus une file : elle retombe sur la file active.");
        }
    }

    public function test_an_oriented_row_says_since_when_the_doctor_is_awaited(): void
    {
        $nurse = $this->nurse();
        $episode = $this->episode('Attend');
        $this->orient($episode, 'CARE', 'COMPLETED');
        $this->orient($episode, 'MEDICINE', 'PENDING', '2026-09-19 09:30');

        $row = $this->actingAs($nurse)->get('/care?filter=waiting_doctor')->viewData('page')['props']['orientations']['data'][0];

        $this->assertSame('En attente du médecin', $row['doctor']['label']);
        $this->assertTrue(CarbonImmutable::parse('2026-09-19 09:30')->equalTo(CarbonImmutable::parse($row['doctor']['since'])));
    }

    public function test_a_patient_sent_back_to_the_doctor_is_waiting_again_and_one_already_seen_is_not(): void
    {
        $nurse = $this->nurse();
        $back = $this->episode('Retour');
        $this->orient($back, 'CARE', 'COMPLETED');
        $this->orient($back, 'MEDICINE', 'COMPLETED', '2026-09-19 09:30');
        $this->orient($back, 'MEDICINE', 'PENDING', '2026-09-19 11:00');

        $seen = $this->episode('Vu');
        $this->orient($seen, 'CARE', 'COMPLETED');
        $this->orient($seen, 'MEDICINE', 'COMPLETED');

        $props = $this->actingAs($nurse)->get('/care?filter=waiting_doctor')->viewData('page')['props'];

        $this->assertSame(['Retour'], $this->names($props));
    }

    public function test_the_ones_waiting_for_the_doctor_read_oldest_first(): void
    {
        $nurse = $this->nurse();
        foreach ([['Recent', '2026-09-19 10:00'], ['Ancien', '2026-09-19 08:00']] as [$name, $at]) {
            $episode = $this->episode($name);
            $this->orient($episode, 'CARE', 'COMPLETED', $at);
            $this->orient($episode, 'MEDICINE', 'PENDING', $at);
        }

        $waiting = $this->actingAs($nurse)->get('/care?filter=waiting_doctor')->viewData('page')['props'];

        $this->assertSame(['Ancien', 'Recent'], collect($waiting['orientations']['data'])->map(fn ($r) => $r['episode']['patient']['last_name'])->all());
    }

    public function test_a_patient_waiting_for_the_doctor_carries_the_number_the_doctor_sees(): void
    {
        $nurse = $this->nurse();
        // Deux patients en file chez le médecin, dans l'ordre d'arrivée chez lui.
        foreach ([['Premier', '2026-09-19 08:30'], ['Second', '2026-09-19 08:40']] as [$name, $at]) {
            $episode = $this->episode($name);
            $this->orient($episode, 'CARE', 'COMPLETED', '2026-09-19 08:00');
            $this->orient($episode, 'MEDICINE', 'PENDING', $at);
        }
        // Un patient qui n'a pas de Soins mais attend aussi le médecin : il a un n° lui aussi.
        $direct = $this->episode('Direct');
        $this->orient($direct, 'MEDICINE', 'PENDING', '2026-09-19 08:20');

        $rows = collect($this->actingAs($nurse)->get('/care?filter=waiting_doctor')->viewData('page')['props']['orientations']['data'])
            ->mapWithKeys(fn ($row) => [$row['episode']['patient']['last_name'] => $row['doctor']['queue_number']]);

        // Direct (08:20) est devant Premier (08:30) puis Second (08:40) : 1, 2, 3 dans la file Médecine.
        $this->assertSame(2, $rows['Premier']);
        $this->assertSame(3, $rows['Second']);

        // Et c'est exactement ce que la file Médecine affiche, filtre ou recherche compris.
        $doctor = User::factory()->create();
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE']);
        $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => 'consultations.view'])->id]);
        $doctor->forceFill(['role_id' => $role->id])->save();

        $medicine = collect($this->actingAs($doctor)->get('/medicine?filter=waiting&q=Second')->viewData('page')['props']['orientations']['data'])
            ->mapWithKeys(fn ($row) => [$row['episode']['patient']['last_name'] => $row['queue_number']]);

        $this->assertSame(3, $medicine['Second'], 'Une recherche ne renumérote pas la file du médecin.');
    }
}
