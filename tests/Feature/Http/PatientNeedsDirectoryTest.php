<?php

namespace Tests\Feature\Http;

use App\Enums\PatientType;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyDispense;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-119 — le répertoire des patients dit où chacun a encore besoin d'aller.
 *
 * Chaque patient tombe dans une seule case : l'ensemble exact des services
 * (Médecine, Soins, Pharmacie) qu'il attend. « Médecine seulement » exclut donc
 * celui qui attend aussi la pharmacie, et la somme des cases est le nombre de
 * patients.
 */
class PatientNeedsDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function viewer(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'RECEPTION'], ['name' => 'Réception']);
        $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => 'patients.view'])->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(string $last = 'Rakoto', array $overrides = []): Patient
    {
        $this->sequence++;

        return Patient::create([
            'patient_number' => sprintf('A-26-%04d', $this->sequence),
            'first_name' => 'Jean',
            'last_name' => $last,
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ]);
    }

    private function episode(Patient $patient, string $status = 'OPEN', string $priority = 'NORMAL', ?string $number = null): Episode
    {
        $this->sequence++;

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $number ?? sprintf('%s-%02d', $patient->patient_number, $this->sequence),
            'status' => $status,
            'priority' => $priority,
            'administrative_status' => 'IN_CARE',
            'started_at' => CarbonImmutable::parse('2026-09-19 08:00'),
        ]);
    }

    private function orient(Episode $episode, string $destination, string $status = 'PENDING', string $at = '2026-09-19 08:30'): EpisodeOrientation
    {
        $active = in_array($status, ['PENDING', 'IN_PROGRESS'], true);

        return EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'destination_module' => $destination,
            'status' => $status,
            'active_key' => $active ? "{$episode->id}:{$destination}" : null,
            'oriented_by' => $this->viewer()->id,
            'oriented_at' => CarbonImmutable::parse($at),
            'accepted_at' => $status === 'IN_PROGRESS' ? CarbonImmutable::parse($at)->addMinutes(10) : null,
        ]);
    }

    private function dispense(Patient $patient, string $status = 'AWAITING_PAYMENT', ?string $at = '2026-09-19 09:00'): PharmacyDispense
    {
        return PharmacyDispense::query()->create([
            'type' => 'INTERNAL',
            'patient_id' => $patient->id,
            'status' => $status,
            'requested_at' => CarbonImmutable::parse($at),
            'requested_by' => $this->viewer()->id,
        ]);
    }

    /** @return array<string, int> clé de combinaison → nombre de patients */
    private function facetCounts(User $user, string $query = ''): array
    {
        $facets = $this->actingAs($user)->get('/patients'.$query)
            ->assertOk()
            ->viewData('page')['props']['needs']['facets'];

        return collect($facets)->pluck('count', 'key')->all();
    }

    /** @return array<int, string> les numéros de patient de la liste, dans l'ordre servi */
    private function listed(User $user, string $query): array
    {
        return collect($this->actingAs($user)->get('/patients?'.ltrim($query, '?'))
            ->assertOk()
            ->viewData('page')['props']['patients']['data'])->pluck('patient_number')->all();
    }

    /**
     * Un patient dans chacune des sept combinaisons, plus deux sans aucun besoin.
     *
     * @return array<string, Patient>
     */
    private function everyCombination(): array
    {
        $patients = [];

        foreach (['M' => ['MEDICINE'], 'C' => ['CARE'], 'P' => ['PHARMACY'], 'MC' => ['MEDICINE', 'CARE'], 'MP' => ['MEDICINE', 'PHARMACY'], 'CP' => ['CARE', 'PHARMACY'], 'MCP' => ['MEDICINE', 'CARE', 'PHARMACY']] as $name => $services) {
            $patient = $this->patient($name);
            $episode = $this->episode($patient);

            foreach ($services as $service) {
                $service === 'PHARMACY'
                    ? $this->dispense($patient)
                    : $this->orient($episode, $service);
            }

            $patients[$name] = $patient;
        }

        $patients['none'] = $this->patient('Aucun');
        // Un passage clos avec une orientation encore ouverte n'attend personne.
        $patients['settled'] = $this->patient('Solde');
        $this->orient($this->episode($patients['settled'], 'CLOSED'), 'MEDICINE');

        return $patients;
    }

    public function test_each_patient_is_counted_once_in_the_exact_combination_of_services_they_wait_for(): void
    {
        $this->everyCombination();

        $counts = $this->facetCounts($this->viewer());

        $this->assertSame([
            'ALL' => 9,
            'MEDICINE' => 1,
            'CARE' => 1,
            'PHARMACY' => 1,
            'MEDICINE,CARE' => 1,
            'MEDICINE,PHARMACY' => 1,
            'CARE,PHARMACY' => 1,
            'MEDICINE,CARE,PHARMACY' => 1,
            'NONE' => 2,
        ], $counts);

        // La somme des cases est le nombre de patients : personne n'est compté
        // deux fois, personne n'est perdu.
        $this->assertSame($counts['ALL'], array_sum(array_diff_key($counts, ['ALL' => 0])));
    }

    public function test_each_filter_returns_exactly_the_patients_of_its_combination(): void
    {
        $patients = $this->everyCombination();
        $user = $this->viewer();

        $this->assertSame([$patients['M']->patient_number], $this->listed($user, 'need=MEDICINE'));
        $this->assertSame([$patients['C']->patient_number], $this->listed($user, 'need=CARE'));
        $this->assertSame([$patients['P']->patient_number], $this->listed($user, 'need=PHARMACY'));
        $this->assertSame([$patients['MC']->patient_number], $this->listed($user, 'need=MEDICINE,CARE'));
        $this->assertSame([$patients['MCP']->patient_number], $this->listed($user, 'need=MEDICINE,CARE,PHARMACY'));
        // L'ordre de l'URL n'y change rien.
        $this->assertSame([$patients['MCP']->patient_number], $this->listed($user, 'need=PHARMACY,MEDICINE,CARE'));
    }

    public function test_none_lists_the_patients_who_wait_for_none_of_the_three_services(): void
    {
        $patients = $this->everyCombination();

        $listed = $this->listed($this->viewer(), 'need=NONE');

        $this->assertEqualsCanonicalizing(
            [$patients['none']->patient_number, $patients['settled']->patient_number],
            $listed,
        );
    }

    public function test_the_selected_combination_is_echoed_in_its_canonical_form(): void
    {
        $this->everyCombination();
        $user = $this->viewer();

        $need = fn (string $query) => $this->actingAs($user)->get('/patients'.$query)->viewData('page')['props']['filters']['need'];

        $this->assertNull($need(''));
        $this->assertSame('MEDICINE,CARE', $need('?need=care,medicine'));
        $this->assertSame('NONE', $need('?need=none'));
        $this->assertNull($need('?need=ALL'));
    }

    public function test_an_unknown_need_filters_nothing(): void
    {
        $this->everyCombination();
        $user = $this->viewer();

        $this->assertCount(9, $this->listed($user, 'need=LABORATORY'));
        $this->assertCount(9, $this->listed($user, 'need=MEDICINE,LABORATORY'));
    }

    public function test_only_a_waiting_or_ongoing_orientation_on_an_open_passage_is_a_need(): void
    {
        $user = $this->viewer();

        $finished = $this->patient('Termine');
        $episode = $this->episode($finished);
        $this->orient($episode, 'MEDICINE', 'COMPLETED');
        $this->orient($episode, 'CARE', 'CANCELLED');

        $cancelled = $this->patient('Annule');
        $this->orient($this->episode($cancelled, 'CANCELLED'), 'CARE');

        $waiting = $this->patient('Attend');
        $this->orient($this->episode($waiting), 'CARE');

        $counts = $this->facetCounts($user);

        $this->assertSame(1, $counts['CARE']);
        $this->assertSame(2, $counts['NONE']);
        $this->assertSame([$waiting->patient_number], $this->listed($user, 'need=CARE'));
    }

    public function test_a_finished_dispense_is_not_a_need_and_neither_is_one_without_a_patient(): void
    {
        $user = $this->viewer();

        foreach (['DISPENSED', 'CANCELLED'] as $status) {
            $this->dispense($this->patient($status), $status);
        }

        // Une vente comptoir ancienne, sans patient, ne se rattache à personne.
        PharmacyDispense::query()->create([
            'type' => 'EXTERNAL',
            'patient_id' => null,
            'status' => 'READY',
            'requested_at' => CarbonImmutable::parse('2026-09-19 09:00'),
            'requested_by' => $user->id,
        ]);

        $counts = $this->facetCounts($user);

        $this->assertSame(0, $counts['PHARMACY']);
        $this->assertSame(2, $counts['NONE']);
    }

    public function test_every_open_dispense_status_makes_the_patient_wait_for_the_pharmacy(): void
    {
        $user = $this->viewer();

        foreach (['AWAITING_INVOICE', 'AWAITING_PAYMENT', 'READY', 'PARTIALLY_DISPENSED'] as $status) {
            $this->dispense($this->patient($status), $status);
        }

        $this->assertSame(4, $this->facetCounts($user)['PHARMACY']);
    }

    public function test_the_counters_follow_the_other_filters_so_a_tile_announces_what_a_click_would_show(): void
    {
        $user = $this->viewer();

        $rakoto = $this->patient('Rakoto');
        $this->orient($this->episode($rakoto), 'MEDICINE');
        $rasoa = $this->patient('Rasoa');
        $this->orient($this->episode($rasoa), 'MEDICINE');
        $mutual = $this->patient('Rabe', ['patient_type' => PatientType::Mutual->value]);
        $this->orient($this->episode($mutual), 'MEDICINE');
        $urgent = $this->patient('Urgent');
        $this->orient($this->episode($urgent, 'OPEN', 'EMERGENCY'), 'MEDICINE');

        $this->assertSame(4, $this->facetCounts($user)['MEDICINE']);

        // La recherche réduit les compteurs comme elle réduit la liste.
        $bySearch = $this->facetCounts($user, '?q=Rakoto');
        $this->assertSame(1, $bySearch['ALL']);
        $this->assertSame(1, $bySearch['MEDICINE']);

        $byType = $this->facetCounts($user, '?type='.PatientType::Mutual->value);
        $this->assertSame(1, $byType['ALL']);
        $this->assertSame(1, $byType['MEDICINE']);

        $byEmergency = $this->facetCounts($user, '?emergency=active');
        $this->assertSame(1, $byEmergency['ALL']);
        $this->assertSame(1, $byEmergency['MEDICINE']);

        // Et le filtre du besoin lui-même ne réduit pas ses propres compteurs :
        // on doit pouvoir passer d'une case à l'autre.
        $this->assertSame(4, $this->facetCounts($user, '?need=MEDICINE')['MEDICINE']);
        $this->assertSame(4, $this->facetCounts($user, '?need=CARE')['MEDICINE']);
    }

    public function test_a_row_carries_where_each_need_stands(): void
    {
        $user = $this->viewer();
        $patient = $this->patient('Complet');
        $episode = $this->episode($patient, 'OPEN', 'NORMAL', 'A-26-0001-01');
        $this->orient($episode, 'MEDICINE', 'IN_PROGRESS', '2026-09-19 08:00');
        $this->orient($episode, 'CARE', 'PENDING', '2026-09-19 08:20');
        $this->dispense($patient, 'PARTIALLY_DISPENSED', '2026-09-19 09:00');

        $needs = $this->actingAs($user)->get('/patients')
            ->viewData('page')['props']['patients']['data'][0]['needs'];

        $this->assertSame(['MEDICINE', 'CARE', 'PHARMACY'], array_column($needs, 'service'));
        $this->assertSame(['IN_PROGRESS', 'PENDING', 'PARTIAL'], array_column($needs, 'state'));
        $this->assertSame(['A-26-0001-01', 'A-26-0001-01', null], array_column($needs, 'episode_number'));
        // Une consultation commencée date de sa prise en charge, une attente de l'orientation.
        $this->assertSame(CarbonImmutable::parse('2026-09-19 08:10')->toIso8601String(), $needs[0]['since']);
        $this->assertSame(CarbonImmutable::parse('2026-09-19 08:20')->toIso8601String(), $needs[1]['since']);
    }

    public function test_a_patient_with_nothing_to_wait_for_has_an_empty_list_of_needs(): void
    {
        $this->patient('Libre');

        $needs = $this->actingAs($this->viewer())->get('/patients')
            ->viewData('page')['props']['patients']['data'][0]['needs'];

        $this->assertSame([], $needs);
    }

    /**
     * Le besoin auprès de la Pharmacie est de l'information de routage : où le
     * patient doit passer. Son état de règlement, lui, appartient à la
     * Pharmacie et à la Caisse (ADR-013, ADR-117) et n'est jamais servi.
     */
    public function test_the_payment_state_of_a_dispense_is_never_served(): void
    {
        $user = $this->viewer();

        // Des noms neutres : le statut ne doit se retrouver dans la charge que
        // si le serveur le sert.
        foreach (['AWAITING_INVOICE', 'AWAITING_PAYMENT', 'READY'] as $index => $status) {
            $this->dispense($this->patient("Patient{$index}"), $status);
        }

        $page = $this->actingAs($user)->get('/patients')->viewData('page')['props'];
        $payload = json_encode($page['patients']['data']);

        foreach (['AWAITING_INVOICE', 'AWAITING_PAYMENT', 'READY'] as $financial) {
            $this->assertStringNotContainsString($financial, $payload);
        }

        $this->assertSame(['PENDING', 'PENDING', 'PENDING'], collect($page['patients']['data'])
            ->flatMap(fn ($row) => array_column($row['needs'], 'state'))->all());
    }

    public function test_the_most_advanced_orientation_speaks_for_a_patient_with_two_open_passages(): void
    {
        $user = $this->viewer();
        $patient = $this->patient('Deux');
        $this->orient($this->episode($patient, 'OPEN', 'NORMAL', 'A-26-0001-01'), 'MEDICINE', 'PENDING', '2026-09-19 07:00');
        $this->orient($this->episode($patient, 'OPEN', 'NORMAL', 'A-26-0001-02'), 'MEDICINE', 'IN_PROGRESS', '2026-09-19 08:00');

        $needs = $this->actingAs($user)->get('/patients')
            ->viewData('page')['props']['patients']['data'][0]['needs'];

        $this->assertCount(1, $needs);
        $this->assertSame('IN_PROGRESS', $needs[0]['state']);
        $this->assertSame('A-26-0001-02', $needs[0]['episode_number']);
        // Deux orientations vers le même service ne font qu'un besoin.
        $this->assertSame(1, $this->facetCounts($user)['MEDICINE']);
    }

    public function test_the_need_filter_follows_the_pagination_links(): void
    {
        $user = $this->viewer();

        for ($i = 0; $i < 22; $i++) {
            $this->orient($this->episode($this->patient("Attend{$i}")), 'MEDICINE');
        }
        $this->patient('Libre');

        $page = $this->actingAs($user)->get('/patients?need=MEDICINE')->viewData('page')['props']['patients'];

        $this->assertSame(22, $page['total']);
        $this->assertCount(20, $page['data']);
        $this->assertStringContainsString('need=MEDICINE', $page['next_page_url']);
    }

    public function test_the_existing_filters_and_summary_are_unchanged(): void
    {
        $user = $this->viewer();
        $this->orient($this->episode($this->patient('Rakoto')), 'MEDICINE');

        $props = $this->actingAs($user)->get('/patients')->viewData('page')['props'];

        $this->assertSame(['type', 'emergency', 'need', 'status', 'segment', 'letter', 'sort'], array_keys($props['filters']));
        $this->assertSame(1, $props['summary']['total']);
        $this->assertSame(1, $props['summary']['in_progress']);
    }

    private function names(array $props): array
    {
        return collect($props['patients']['data'])->pluck('last_name')->sort()->values()->all();
    }

    private function settling(Patient $patient, string $number): Episode
    {
        $episode = $this->episode($patient, 'OPEN', 'NORMAL', $number);
        $episode->forceFill(['administrative_status' => 'PENDING_SETTLEMENT'])->save();

        return $episode;
    }

    public function test_the_tabs_sort_patients_like_the_presence_badge_of_their_row(): void
    {
        $user = $this->viewer();

        $this->episode($this->patient('EnSoins'), 'OPEN', 'NORMAL', 'S-1');
        $this->settling($this->patient('Regle'), 'R-1');
        $this->episode($this->patient('Ferme'), 'CLOSED', 'NORMAL', 'F-1');
        $this->patient('SansPassage');

        $open = $this->actingAs($user)->get('/patients?status=open')->viewData('page')['props'];
        $settlement = $this->actingAs($user)->get('/patients?status=settlement')->viewData('page')['props'];
        $none = $this->actingAs($user)->get('/patients?status=none')->viewData('page')['props'];

        $this->assertSame(['EnSoins'], $this->names($open));
        $this->assertSame(['Regle'], $this->names($settlement));
        $this->assertSame(['Ferme', 'SansPassage'], $this->names($none));
        $this->assertSame('settlement', $settlement['filters']['status']);
        $this->assertSame(['all' => 4, 'open' => 1, 'settlement' => 1, 'none' => 2], $open['segments']['status']);
    }

    public function test_a_patient_still_in_care_is_not_only_waiting_for_settlement(): void
    {
        $user = $this->viewer();
        $patient = $this->patient('Deux');
        $this->settling($patient, 'D-1');
        $this->episode($patient, 'OPEN', 'NORMAL', 'D-2');

        $settlement = $this->actingAs($user)->get('/patients?status=settlement')->viewData('page')['props'];
        $open = $this->actingAs($user)->get('/patients?status=open')->viewData('page')['props'];

        $this->assertSame([], $this->names($settlement));
        $this->assertSame(['Deux'], $this->names($open));
    }

    public function test_an_open_emergency_is_always_in_the_open_tab(): void
    {
        $user = $this->viewer();
        $episode = $this->settling($this->patient('Urgent'), 'U-1');
        $episode->forceFill(['priority' => 'EMERGENCY'])->save();

        $open = $this->actingAs($user)->get('/patients?status=open')->viewData('page')['props'];
        $settlement = $this->actingAs($user)->get('/patients?status=settlement')->viewData('page')['props'];

        $this->assertSame(['Urgent'], $this->names($open));
        $this->assertSame([], $this->names($settlement));
    }

    public function test_the_tab_counts_follow_the_other_filters_and_the_need_cards_follow_the_tab(): void
    {
        $user = $this->viewer();
        $this->orient($this->episode($this->patient('Attend'), 'OPEN', 'NORMAL', 'A-1'), 'MEDICINE');
        $this->settling($this->patient('Regle'), 'R-1');

        $withNeed = $this->actingAs($user)->get('/patients?need=MEDICINE')->viewData('page')['props'];
        $this->assertSame(['all' => 1, 'open' => 1, 'settlement' => 0, 'none' => 0], $withNeed['segments']['status']);

        $settlement = $this->actingAs($user)->get('/patients?status=settlement')->viewData('page')['props'];
        $this->assertSame(0, collect($settlement['needs']['facets'])->firstWhere('key', 'MEDICINE')['count']);
        $this->assertSame(1, collect($settlement['needs']['facets'])->firstWhere('key', 'ALL')['count']);
    }

    public function test_an_unknown_status_filters_nothing(): void
    {
        $user = $this->viewer();
        $this->patient('Rakoto');

        $props = $this->actingAs($user)->get('/patients?status=zzz')->viewData('page')['props'];

        $this->assertCount(1, $props['patients']['data']);
        $this->assertNull($props['filters']['status']);
    }
}
