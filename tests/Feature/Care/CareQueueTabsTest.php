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
 * ADR-177 (remplace ADR-124) — la page Soins montre les passages ouverts en
 * trois blocs qui ne se mélangent pas : en attente (numérotés par ordre
 * d'arrivée), en cours chez moi, terminés chez moi.
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

    private function episode(string $last, string $arrivedAt = '2026-09-19 08:00'): Episode
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
            'started_at' => CarbonImmutable::parse($arrivedAt),
            // L'accueil est confirmé à la Réception : le passage est visible des services.
            'service_plan_finalized_at' => CarbonImmutable::parse($arrivedAt),
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
        return collect($props['passages']['data'])->map(fn ($row) => $row['episode']['patient']['last_name'])->sort()->values()->all();
    }

    /**
     * ADR-177 — trois blocs exclusifs : un passage n'est que dans l'un d'eux, et
     * la somme de leurs comptes est le nombre de passages que les Soins voient.
     * Un passage que la Médecine a déjà pris reste « en attente » pour les Soins :
     * voir n'est pas prendre, et il n'a pas été vu aux Soins.
     */
    public function test_the_board_sorts_every_active_passage_into_exclusive_blocks(): void
    {
        $nurse = $this->nurse();
        $doctor = User::factory()->create(['name' => 'Dr Rakoto']);

        $active = $this->episode('EnSoins', '2026-09-19 07:00');
        $this->orient($active, 'CARE', 'IN_PROGRESS', by: $nurse);

        $waiting = $this->episode('AttendMedecin', '2026-09-19 07:10');
        $this->orient($waiting, 'CARE', 'COMPLETED');
        $this->orient($waiting, 'MEDICINE', 'PENDING');

        $seen = $this->episode('EnConsultation', '2026-09-19 07:20');
        $this->orient($seen, 'MEDICINE', 'IN_PROGRESS', by: $doctor);

        $requested = $this->episode('OrienteVersSoins', '2026-09-19 07:30');
        $this->orient($requested, 'CARE', 'PENDING');

        $free = $this->episode('SansOrientation', '2026-09-19 07:40');

        $get = fn (string $view) => $this->actingAs($nurse)->get("/care?view={$view}")->viewData('page')['props'];

        $this->assertSame(['EnConsultation', 'OrienteVersSoins', 'SansOrientation'], $this->names($get('waiting')));
        $this->assertSame(['EnSoins'], $this->names($get('in_progress')));
        $this->assertSame(['AttendMedecin'], $this->names($get('completed')));

        $counts = $get('waiting')['counts'];
        $this->assertSame(['waiting' => 3, 'suggested' => 0, 'in_progress' => 1, 'completed' => 1, 'emergency' => 0], $counts);
        $this->assertSame(5, $counts['waiting'] + $counts['in_progress'] + $counts['completed']);

        // Chaque patient en attente a son n° de file, par ordre d'arrivée — qu'une vraie
        // orientation l'ait envoyé ici ou non.
        $numbers = collect($get('waiting')['passages']['data'])
            ->mapWithKeys(fn ($row) => [$row['episode']['patient']['last_name'] => $row['module']['queue_number']])
            ->all();
        $this->assertSame(['EnConsultation' => 1, 'OrienteVersSoins' => 2, 'SansOrientation' => 3], $numbers);
        // Un patient en cours ou terminé ne tient plus de place dans la file.
        $this->assertNull($get('in_progress')['passages']['data'][0]['module']['queue_number']);
        $this->assertNull($get('completed')['passages']['data'][0]['module']['queue_number']);

        // Une vue inconnue ou héritée retombe sur la file d'attente.
        foreach (['all', 'requested', 'active', 'waiting_doctor', 'oriented'] as $legacy) {
            $this->assertSame('waiting', $get($legacy)['view'], "« {$legacy} » n'est plus une vue.");
        }
    }

    public function test_a_row_says_where_the_patient_is_elsewhere_and_since_when_the_doctor_is_awaited(): void
    {
        $nurse = $this->nurse();
        $episode = $this->episode('Attend');
        $this->orient($episode, 'CARE', 'COMPLETED');
        $this->orient($episode, 'MEDICINE', 'PENDING', '2026-09-19 09:30');

        $row = $this->actingAs($nurse)->get('/care?view=completed')->viewData('page')['props']['passages']['data'][0];

        $this->assertSame('COMPLETED', $row['module']['state']);
        $this->assertSame('MEDICINE', $row['elsewhere'][0]['module']);
        $this->assertSame('PENDING', $row['elsewhere'][0]['state']);
        $this->assertSame(1, $row['elsewhere'][0]['queue_number']);
    }

    public function test_a_patient_waiting_for_the_doctor_carries_the_number_the_doctor_sees(): void
    {
        $nurse = $this->nurse();
        // Deux patients passés aux Soins, qui attendent maintenant le médecin.
        foreach ([['Premier', '2026-09-19 07:30'], ['Second', '2026-09-19 07:40']] as [$name, $arrived]) {
            $episode = $this->episode($name, $arrived);
            $this->orient($episode, 'CARE', 'COMPLETED', '2026-09-19 08:00');
            $this->orient($episode, 'MEDICINE', 'PENDING', '2026-09-19 08:30');
        }
        // Un patient arrivé avant eux, qui attend aussi le médecin : il a un n° lui aussi.
        $direct = $this->episode('Direct', '2026-09-19 07:20');
        $this->orient($direct, 'MEDICINE', 'PENDING', '2026-09-19 08:40');

        $rows = collect($this->actingAs($nurse)->get('/care?view=completed')->viewData('page')['props']['passages']['data'])
            ->mapWithKeys(fn ($row) => [$row['episode']['patient']['last_name'] => $row['elsewhere'][0]['queue_number']]);

        // Par ordre d'arrivée à la clinique : Direct (07:20), Premier (07:30), Second (07:40).
        $this->assertSame(2, $rows['Premier']);
        $this->assertSame(3, $rows['Second']);

        // Et c'est exactement ce que la file Médecine affiche, vue ou recherche comprise.
        $doctor = User::factory()->create();
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE']);
        $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => 'consultations.view'])->id]);
        $doctor->forceFill(['role_id' => $role->id])->save();

        $medicine = collect($this->actingAs($doctor)->get('/medicine?view=waiting&q=Second')->viewData('page')['props']['passages']['data'])
            ->mapWithKeys(fn ($row) => [$row['episode']['patient']['last_name'] => $row['module']['queue_number']]);

        $this->assertSame(3, $medicine['Second'], 'Une recherche ne renumérote pas la file du médecin.');
    }
}
