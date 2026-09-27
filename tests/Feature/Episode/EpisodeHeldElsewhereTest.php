<?php

namespace Tests\Feature\Episode;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\EpisodeHeldElsewhere;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-177, amendement du 2026-09-27 — un patient déjà pris en charge en
 * Médecine ou aux Soins ne se prend pas une seconde fois depuis le tableau
 * d'un autre service : le bouton devient « En cours », le serveur refuse.
 *
 * Jamais refusés : une demande adressée à ce service (ordre de soins,
 * transmission), une urgence.
 */
class EpisodeHeldElsewhereTest extends TestCase
{
    use RefreshDatabase;

    private ?User $receptionist = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    /** Le cas signalé : Jeanine en consultation chez le médecin, visible des Soins. */
    public function test_a_patient_in_consultation_reads_en_cours_at_care_and_cannot_be_taken(): void
    {
        $episode = $this->arrival('CONSULT-SPEC', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $doctor = $this->user('MEDICINE', 'Dr Eliot TSARAMANANA');
        $nurse = $this->user('NURSE');

        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode))->assertRedirect();

        $row = $this->row($nurse, '/care', $episode);
        $this->assertSame('MEDICINE', $row['held_elsewhere']['module']);
        $this->assertSame('en Médecine', $row['held_elsewhere']['where']);
        $this->assertSame('Dr Eliot TSARAMANANA', $row['held_elsewhere']['by']);
        $this->assertStringContainsString('déjà pris en charge en Médecine par Dr Eliot TSARAMANANA', $row['held_elsewhere']['message']);
        $this->assertNull($row['actions']['take_charge_url'], 'Pas de « Prendre » : le bouton devient « En cours ».');
        $this->assertSame('NONE', $row['status'], 'Il n’entre pas dans le garde-fou « un patient attend avant celui-ci ».');

        // Le serveur refuse de toute façon : l'écran n'est jamais la seule garde.
        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))
            ->assertSessionHasErrors('episode');
        $this->assertSame(0, EpisodeOrientation::query()->where('destination_module', CatalogModule::Care->value)->count());
    }

    public function test_a_patient_in_care_reads_en_cours_in_medicine_and_keeps_his_place(): void
    {
        $inCare = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $next = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $nurse = $this->user('NURSE', 'Infirmière Hanta');
        $doctor = $this->user('MEDICINE');

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $inCare))->assertRedirect();

        $held = $this->row($doctor, '/medicine', $inCare);
        $this->assertSame('CARE', $held['held_elsewhere']['module']);
        $this->assertSame('aux Soins', $held['held_elsewhere']['where']);
        $this->assertNull($held['actions']['take_charge_url']);
        $this->assertSame(1, $held['module']['queue_number'], 'Il garde sa place : il reviendra.');
        $this->assertSame('NONE', $held['status']);

        // Le suivant se prend sans que le garde-fou croie que le n° 1 attend.
        $free = $this->row($doctor, '/medicine', $next);
        $this->assertNull($free['held_elsewhere']);
        $this->assertSame('PENDING', $free['status']);
        $this->assertNotNull($free['actions']['take_charge_url']);

        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $inCare))
            ->assertSessionHasErrors('episode');
        $this->assertSame(0, EpisodeOrientation::query()->where('episode_id', $inCare->id)->where('destination_module', CatalogModule::Medicine->value)->count());

        // La transmission des Soins est une vraie demande : elle se prend.
        $transmitted = app(CreateEpisodeOrientationAction::class)
            ->execute($inCare, CatalogModule::Care, CatalogModule::Medicine, $nurse, 'Soins terminés, transmis au médecin.');
        $this->assertSame('REQUESTED', $this->row($doctor, '/medicine', $inCare)['module']['state']);
        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $inCare))->assertSessionHasNoErrors();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $transmitted->fresh()->status);
    }

    /** ADR-088 : le médecin demande un soin sans clore sa consultation — les deux travaillent en parallèle. */
    public function test_a_care_request_from_the_doctor_is_taken_while_the_consultation_stays_open(): void
    {
        $episode = $this->arrival('CONSULT-SPEC', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $doctor = $this->user('MEDICINE');
        $nurse = $this->user('NURSE');
        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode))->assertRedirect();

        $request = app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Medicine, CatalogModule::Care, $doctor, 'Soin demandé par le médecin.');

        $row = $this->row($nurse, '/care', $episode);
        $this->assertNull($row['held_elsewhere']);
        $this->assertNotNull($row['actions']['take_charge_url']);

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))->assertSessionHasNoErrors();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $request->fresh()->status);
    }

    /** ADR-021 : une urgence se prend en parallèle, aux Soins comme en Médecine. */
    public function test_an_emergency_is_never_held(): void
    {
        $episode = $this->arrival('CONSULT-SPEC', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $doctor = $this->user('MEDICINE');
        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode))->assertRedirect();

        $this->assertNotNull(EpisodeHeldElsewhere::holder($episode->fresh(), CatalogModule::Care));

        $episode->forceFill(['priority' => EpisodePriority::Emergency])->save();
        $this->assertNull(EpisodeHeldElsewhere::holder($episode->fresh(), CatalogModule::Care));
    }

    /** La Maternité ne tient pas le patient au sens de cette règle : la demande nomme la Médecine et les Soins. */
    public function test_maternity_does_not_hold_the_patient(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $midwifeLike = $this->user('NURSE');
        $maternity = app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Reception, CatalogModule::Maternity, $midwifeLike, 'Suivi');
        $maternity->accept($midwifeLike);

        $this->assertNull(EpisodeHeldElsewhere::holder($episode->fresh(), CatalogModule::Care));
        $this->assertNull(EpisodeHeldElsewhere::holder($episode->fresh(), CatalogModule::Medicine));
    }

    // ── Outils ───────────────────────────────────────────────────────────

    private function arrival(string $code, ReceptionRoutingMode $route, CatalogModule $module): Episode
    {
        $reception = $this->receptionist ??= $this->user('RECEPTION');
        $item = CatalogItem::query()->where('code', $code)->first() ?? CatalogItem::query()->create([
            'code' => $code, 'name' => $code, 'type' => CatalogItemType::Service, 'module' => $module,
            'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'reception_selectable' => true, 'reception_routing_mode' => $route,
            'created_by' => $reception->id, 'updated_by' => $reception->id,
        ]);
        CatalogTariff::query()->firstOrCreate(['catalog_item_id' => $item->id, 'tariff_category' => CatalogTariffCategory::Standard], [
            'amount' => '20000.00', 'currency' => 'MGA', 'effective_from' => now(), 'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test', 'created_by' => $reception->id,
        ]);

        $episode = app(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1990-04-02', 'sex' => 'F',
        ]), actor: $reception);
        $episode = app(SetEpisodeFinancialContextAction::class)->execute($episode, EpisodeFinancialMode::Self, [], $reception);

        $this->actingAs($reception)->post(route('reception.passages.services.store', $episode), [
            'catalog_lines' => [['catalog_item_uuid' => $item->uuid, 'quantity' => 1]],
            'payment_choice' => 'LATER',
        ])->assertSessionHasNoErrors();

        return $episode->fresh();
    }

    /** @return array<string, mixed>|null */
    private function row(User $user, string $url, Episode $episode): ?array
    {
        return collect($this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['passages']['data'])
            ->firstWhere('uuid', $episode->uuid);
    }

    private function user(string $role, ?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'role_id' => Role::query()->where('code', $role)->value('id'),
            'name' => $name,
        ]));
    }
}
