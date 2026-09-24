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
use App\Enums\ReceptionNextStep;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\EpisodeEntryPath;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-177, amendement — par où un passage devrait entrer.
 *
 * ```text
 * Médecine devant un patient attendu aux Soins   prévenue, décide (soins elle-même ou consultation)
 * Soins devant un patient attendu en Médecine    informés, et refusés par le serveur
 * ```
 *
 * Sur les socles de rôle réels du site : la Médecine n'a pas `care.create`
 * (ADR-157), donc « faire les soins soi-même » exige une exception accordée.
 */
class EpisodeEntryPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_medicine_is_told_a_patient_is_expected_at_care_first_and_keeps_the_decision(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $doctor = $this->doctor();

        $row = $this->row($doctor, '/medicine', $episode);

        $this->assertSame(EpisodeEntryPath::CARE_FIRST, $row['pathway']['code']);
        $this->assertFalse($row['pathway']['blocking']);
        $this->assertContains('Besoin : CONSULT-GEN (Soins puis Médecine).', $row['pathway']['reasons']);
        // Sans le droit d'ouvrir une fiche Soins, le médecin ne se voit pas proposer d'en faire.
        $this->assertNull($row['pathway']['care_take_charge_url']);
        // Rien n'est retiré au médecin : il peut consulter.
        $this->assertNotNull($row['actions']['take_charge_url']);
        $this->assertSame(1, $row['module']['queue_number']);

        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode))->assertSessionHasNoErrors();

        $this->assertSame(EpisodeOrientationStatus::InProgress, EpisodeOrientation::query()->sole()->status);
    }

    public function test_a_doctor_with_care_rights_does_the_care_himself_from_the_reminder(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $doctor = $this->doctor();
        $this->allow($doctor, 'care.create');

        $row = $this->row($doctor->fresh(), '/medicine', $episode);
        $this->assertSame(route('care.passages.take-charge', $episode), $row['pathway']['care_take_charge_url']);

        $this->actingAs($doctor->fresh())->post($row['pathway']['care_take_charge_url'])->assertSessionHasNoErrors();

        $care = EpisodeOrientation::query()->sole();
        $this->assertSame(CatalogModule::Care, $care->destination_module);
        $this->assertSame($doctor->id, $care->accepted_by);
        // Les Soins ont le patient : la Médecine n'a plus rien à lui rappeler.
        $this->assertNull($this->row($doctor->fresh(), '/medicine', $episode)['pathway']);
    }

    public function test_the_reception_suggestion_outweighs_the_route_of_the_catalogue(): void
    {
        // Suggéré « Médecine » : la consultation générale ne renvoie plus aux Soins.
        $consultation = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine, [ReceptionNextStep::Medicine->value]);
        $this->assertNull($this->row($this->doctor(), '/medicine', $consultation)['pathway']);
        // Le besoin passe tout de même par les Soins : ils ne sont pas refusés.
        $care = $this->row($this->nurse(), '/care', $consultation);
        $this->assertNull($care['pathway']);
        $this->assertNotNull($care['actions']['take_charge_url']);

        // Suggéré « Soins » : un ECG, qui va d'ordinaire au médecin, y passe d'abord.
        $ecg = $this->arrival('ECG-REPOS', ReceptionRoutingMode::MedicineDirect, CatalogModule::Imaging, [ReceptionNextStep::Care->value]);
        $row = $this->row($this->doctor(), '/medicine', $ecg);
        $this->assertSame(EpisodeEntryPath::CARE_FIRST, $row['pathway']['code']);
        $this->assertContains('L’accueil a suggéré les Soins comme prochaine étape.', $row['pathway']['reasons']);
        $this->assertNull($this->row($this->nurse(), '/care', $ecg)['pathway']);
    }

    public function test_care_is_informed_and_refused_for_a_patient_expected_directly_in_medicine(): void
    {
        $episode = $this->arrival('ECG-REPOS', ReceptionRoutingMode::MedicineDirect, CatalogModule::Imaging);
        $nurse = $this->nurse();

        $row = $this->row($nurse, '/care', $episode);

        $this->assertSame(EpisodeEntryPath::MEDICINE_ONLY, $row['pathway']['code']);
        $this->assertTrue($row['pathway']['blocking']);
        $this->assertContains('Besoin : ECG-REPOS (Médecine directement).', $row['pathway']['reasons']);
        // Aucune adresse de prise en charge, aucune place dans la file des Soins.
        $this->assertNull($row['actions']['take_charge_url']);
        $this->assertNull($row['module']['queue_number']);
        $this->assertSame('NONE', $row['status']);

        // Le serveur refuse de toute façon : l'écran n'est jamais la seule garde.
        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))
            ->assertSessionHasErrors(['episode' => EpisodeEntryPath::refusalMessage()]);
        $this->assertSame(0, EpisodeOrientation::query()->count());

        // Le médecin, lui, le prend sans rappel.
        $doctorRow = $this->row($this->doctor(), '/medicine', $episode);
        $this->assertNull($doctorRow['pathway']);
        $this->assertSame(1, $doctorRow['module']['queue_number']);
    }

    public function test_a_care_request_from_the_doctor_or_an_emergency_is_never_refused(): void
    {
        $episode = $this->arrival('ECG-REPOS', ReceptionRoutingMode::MedicineDirect, CatalogModule::Imaging);
        $waiting = app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Medicine, CatalogModule::Care, $this->doctor(), 'Soin demandé par le médecin.');
        $nurse = $this->nurse();

        $row = $this->row($nurse, '/care', $episode);
        $this->assertSame('REQUESTED', $row['module']['state']);
        $this->assertNull($row['pathway']);

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))
            ->assertRedirect(route('care.orientations.show', $waiting));
        $this->assertSame(EpisodeOrientationStatus::InProgress, $waiting->fresh()->status);

        $emergency = $this->arrival('ECG-REPOS', ReceptionRoutingMode::MedicineDirect, CatalogModule::Imaging);
        $emergency->forceFill(['priority' => EpisodePriority::Emergency])->save();
        $this->assertNull(EpisodeEntryPath::guard($emergency->fresh(), CatalogModule::Care));
        $this->assertNull(EpisodeEntryPath::guard($emergency->fresh(), CatalogModule::Medicine));
    }

    public function test_the_patient_expected_in_medicine_holds_no_place_in_the_care_queue(): void
    {
        $ecg = $this->arrival('ECG-REPOS', ReceptionRoutingMode::MedicineDirect, CatalogModule::Imaging);
        $injection = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $nurse = $this->nurse();

        $this->assertNull($this->row($nurse, '/care', $ecg)['module']['queue_number']);
        $row = $this->row($nurse, '/care', $injection);
        $this->assertSame(1, $row['module']['queue_number']);
        $this->assertSame('PENDING', $row['status']);
        // Il reste visible des Soins : la visibilité ne dépend pas du parcours (ADR-177).
        $this->assertSame(2, collect($this->page($nurse, '/care'))->count());
    }

    public function test_the_reminder_is_served_only_to_whom_could_take_the_patient(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $doctor = $this->doctor();
        $doctor->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'consultations.create')->value('id') => ['effect' => 'deny'],
        ]);

        $row = $this->row($doctor->fresh(), '/medicine', $episode);
        $this->assertNull($row['pathway']);
        $this->assertNull($row['actions']['take_charge_url']);
    }

    // ── Aides ────────────────────────────────────────────────────────────

    /** @param list<string>|null $nextSteps */
    private function arrival(string $code, ReceptionRoutingMode $route, CatalogModule $module, ?array $nextSteps = null): Episode
    {
        $reception = $this->receptionist();
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

        $episode = app(CreateEpisodeAction::class)->execute($this->patient(), actor: $reception);
        $episode = app(SetEpisodeFinancialContextAction::class)->execute($episode, EpisodeFinancialMode::Self, [], $reception);

        $this->actingAs($reception)->post(route('reception.passages.services.store', $episode), array_filter([
            'catalog_lines' => [['catalog_item_uuid' => $item->uuid, 'quantity' => 1]],
            'payment_choice' => 'LATER',
            'next_steps' => $nextSteps,
        ], fn ($value) => $value !== null))->assertSessionHasNoErrors();

        return $episode->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function page(User $user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['passages']['data'];
    }

    /** @return array<string, mixed>|null */
    private function row(User $user, string $url, Episode $episode): ?array
    {
        return collect($this->page($user, $url))->firstWhere('uuid', $episode->uuid);
    }

    private function allow(User $user, string $permission): void
    {
        $user->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', $permission)->value('id') => ['effect' => 'allow'],
        ]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'birth_date' => '1990-04-02', 'sex' => 'F',
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', $role)->value('id')]);
    }

    private function receptionist(): User
    {
        return $this->receptionist ??= $this->user('RECEPTION');
    }

    private function nurse(): User
    {
        return $this->nurse ??= $this->user('NURSE');
    }

    private function doctor(): User
    {
        return $this->user('MEDICINE');
    }

    private ?User $receptionist = null;

    private ?User $nurse = null;
}
