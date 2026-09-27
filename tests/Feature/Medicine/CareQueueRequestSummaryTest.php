<?php

namespace Tests\Feature\Medicine;

use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\CatalogItem;
use App\Models\Consultation;
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
 * ADR-118 — ce que la file Soins dit de chaque demande.
 *
 * La file groupait les orientations par patient et les appelait des « passages » :
 * deux demandes de soins du même passage s'affichaient comme deux passages au
 * numéro identique, sans rien pour les distinguer. Chaque orientation Soins
 * porte désormais sa demande — qui l'a faite, la suite décidée, les actes —,
 * lue par la même règle que le parcours du passage.
 *
 * ADR-177 — le tableau des passages n'affiche plus qu'une ligne par passage,
 * qui porte la demande de l'orientation Soins en cours (l'active, sinon la
 * dernière terminée). L'historique des demandes se lit sur le passage (ADR-117).
 */
class CareQueueRequestSummaryTest extends TestCase
{
    use RefreshDatabase;

    private ?User $doctor = null;

    private function nurse(array $extra = []): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'NURSE']);

        foreach (['care.view', 'care.create', ...$extra] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function doctor(): User
    {
        return $this->doctor ??= User::factory()->create([
            'name' => 'Dr Rakoto',
            'role_id' => Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE'])->id,
        ]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation, 2: Consultation} */
    private function episodeInConsultation(): array
    {
        $patient = Patient::create([
            'patient_number' => 'A-26-0009',
            'first_name' => 'Malala',
            'last_name' => 'Rasoamifidy',
            'birth_date' => '1980-05-12',
            'sex' => 'F',
        ]);
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'A-26-0009-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => CarbonImmutable::parse('2026-09-18 07:43'),
        ]);
        $medicine = EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'destination_module' => 'MEDICINE',
            'status' => 'IN_PROGRESS',
            'active_key' => "{$episode->id}:MEDICINE",
            'oriented_by' => $this->doctor()->id,
            'oriented_at' => CarbonImmutable::parse('2026-09-18 07:43'),
        ]);
        $consultation = Consultation::create([
            'episode_id' => $episode->id,
            'doctor_id' => $this->doctor()->id,
            'reason' => 'Échographie',
            'consulted_at' => CarbonImmutable::parse('2026-09-18 07:44'),
        ]);

        return [$episode, $medicine, $consultation];
    }

    private function careOrientation(Episode $episode, string $status, string $at, ?string $activeKey = null): EpisodeOrientation
    {
        return EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => 'MEDICINE',
            'destination_module' => 'CARE',
            'status' => $status,
            'active_key' => $activeKey,
            'oriented_by' => $this->doctor()->id,
            'oriented_at' => CarbonImmutable::parse($at),
            'completed_at' => $status === 'COMPLETED' ? CarbonImmutable::parse($at)->addMinutes(20) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(User $nurse, string $view = 'all'): array
    {
        return $this->actingAs($nurse)->get("/care?view={$view}")
            ->assertOk()
            ->viewData('page')['props']['passages']['data'];
    }

    private function request(Episode $episode, Consultation $consultation, EpisodeOrientation $medicine, EpisodeOrientation $care, bool $returns, string $act): CareOrder
    {
        $order = CareOrder::create([
            'episode_id' => $episode->id,
            'consultation_id' => $consultation->id,
            'source_orientation_id' => $medicine->id,
            'care_orientation_id' => $care->id,
            'requested_by' => $this->doctor()->id,
            'requires_return_to_medicine' => $returns,
            'status' => 'PENDING',
            'ordered_at' => CarbonImmutable::parse('2026-09-18 12:00'),
        ]);
        $item = CatalogItem::create([
            'code' => strtoupper(substr(md5($act), 0, 8)),
            'name' => $act,
            'type' => 'SERVICE',
            'module' => 'CARE',
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'clinician_orderable' => true,
            'created_by' => $this->doctor()->id,
            'updated_by' => $this->doctor()->id,
        ]);
        CareOrderItem::create([
            'care_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'catalog_item_code_snapshot' => $item->code,
            'catalog_item_name_snapshot' => $act,
            'quantity' => '1.00',
        ]);

        return $order;
    }

    public function test_two_requests_of_one_passage_are_one_row_carrying_the_latest_request(): void
    {
        [$episode, $medicine, $consultation] = $this->episodeInConsultation();
        $first = $this->careOrientation($episode, 'COMPLETED', '2026-09-18 12:57');
        $second = $this->careOrientation($episode, 'COMPLETED', '2026-09-18 16:28');
        $this->request($episode, $consultation, $medicine, $first, returns: true, act: 'Injection IM');
        $this->request($episode, $consultation, $medicine, $second, returns: false, act: 'Pansement');

        // Le patient attend le retour du médecin : les Soins ont terminé.
        $medicine->forceFill(['status' => 'PENDING', 'accepted_by' => null, 'accepted_at' => null])->saveQuietly();

        $rows = $this->rows($this->nurse(['care_orders.view']), 'completed');

        // Un passage, une ligne : deux demandes ne se lisent plus comme deux passages.
        $this->assertCount(1, $rows);
        $this->assertSame($episode->uuid, $rows[0]['uuid']);
        $this->assertSame($second->uuid, $rows[0]['module']['orientation_uuid']);

        $request = $rows[0]['care_request'];
        $this->assertSame(['Dr Rakoto'], $request['requested_by']);
        $this->assertSame('DIRECT_EXIT', $request['follow_up']['code']);
        $this->assertSame(['Pansement'], array_column($request['items'], 'name'));
        $this->assertSame('À réaliser', $request['items'][0]['state_label']);

        // Où le patient attend, ailleurs : le médecin, avec son n° de la file Médecine.
        $this->assertSame('MEDICINE', $rows[0]['elsewhere'][0]['module']);
        $this->assertSame(1, $rows[0]['elsewhere'][0]['queue_number']);
    }

    public function test_the_acts_need_care_orders_view_but_the_follow_up_is_routing_information(): void
    {
        [$episode, $medicine, $consultation] = $this->episodeInConsultation();
        $care = $this->careOrientation($episode, 'PENDING', '2026-09-18 12:57', activeKey: "{$episode->id}:CARE");
        $this->request($episode, $consultation, $medicine, $care, returns: false, act: 'Injection IM');

        $request = $this->rows($this->nurse())[0]['care_request'];

        $this->assertSame('DIRECT_EXIT', $request['follow_up']['code']);
        $this->assertSame(['Dr Rakoto'], $request['requested_by']);
        $this->assertSame([], $request['items']);
    }

    public function test_an_orientation_with_no_doctor_request_behind_it_has_no_request_to_show(): void
    {
        [$episode] = $this->episodeInConsultation();
        $this->careOrientation($episode, 'PENDING', '2026-09-18 08:00', activeKey: "{$episode->id}:CARE");

        $row = $this->rows($this->nurse(['care_orders.view']))[0];

        // Une orientation issue de l'arrivée n'a pas de suite à annoncer : elle ne l'invente pas.
        $this->assertNull($row['care_request']);
    }

    public function test_two_requests_sharing_one_still_active_orientation_lose_neither(): void
    {
        [$episode, $medicine, $consultation] = $this->episodeInConsultation();
        $care = $this->careOrientation($episode, 'PENDING', '2026-09-18 12:00', activeKey: "{$episode->id}:CARE");
        $this->request($episode, $consultation, $medicine, $care, returns: false, act: 'Injection IM');
        $this->request($episode, $consultation, $medicine, $care, returns: true, act: 'Pansement');

        $request = $this->rows($this->nurse(['care_orders.view']))[0]['care_request'];

        $this->assertSame(['Injection IM', 'Pansement'], array_column($request['items'], 'name'));
        $this->assertSame('RETURN_TO_MEDICINE', $request['follow_up']['code']);
        $this->assertSame(['Dr Rakoto'], $request['requested_by']);
    }
}
