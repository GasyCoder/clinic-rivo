<?php

namespace Tests\Feature\Episode;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\ReceptionNextStep;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeReceptionNextStep;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-177 — cinq notions que l'accueil confondait :
 *
 * ```text
 * besoin              pourquoi le patient vient — il reste facturé
 * prochaine étape     la suggestion de l'accueil — facultative, indicative
 * visibilité          tout passage ouvert et accueilli, pour tout service autorisé
 * prise en charge     un vrai geste, tracé, qui crée ou accepte l'orientation
 * orientation réelle  transmission, demande d'un médecin, urgence — inchangée
 * ```
 *
 * Les scénarios suivent la demande, sur les socles de rôle réels du site.
 */
class ReceptionNextStepAndBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    // ── 1–2. Injection, avec et sans prochaine étape ─────────────────────

    public function test_an_injection_confirmed_without_next_step_is_seen_by_care_and_medicine(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);

        $this->confirm($episode, 'INJ-IM')->assertSessionHasNoErrors();

        $this->assertSame(0, EpisodeOrientation::query()->count());
        $this->assertSame(0, EpisodeReceptionNextStep::query()->count());

        foreach (['/care' => $this->nurse(), '/medicine' => $this->doctor()] as $url => $user) {
            $row = $this->row($user, $url, $episode);
            $this->assertNotNull($row, "{$url} ne voit pas le passage");
            $this->assertSame('NONE', $row['module']['state']);
            $this->assertSame([], $row['next_steps']);
            $this->assertNotNull($row['actions']['take_charge_url']);
            $this->assertSame([], $this->uuids($user, "{$url}?view=suggested"));
        }
    }

    public function test_an_injection_suggested_for_care_is_still_seen_by_medicine(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);

        $this->confirm($episode, 'INJ-IM', [ReceptionNextStep::Care->value])->assertSessionHasNoErrors();

        $this->assertSame(['CARE'], EpisodeReceptionNextStep::query()->pluck('module')->map->value->all());
        $this->assertSame([$episode->uuid], $this->uuids($this->nurse(), '/care?view=suggested'));
        // La suggestion ne cache rien : la Médecine voit le passage, sans qu'il lui soit suggéré.
        $this->assertSame([$episode->uuid], $this->uuids($this->doctor(), '/medicine'));
        $this->assertSame([], $this->uuids($this->doctor(), '/medicine?view=suggested'));
        $this->assertFalse($this->row($this->doctor(), '/medicine', $episode)['suggested_for_me']);
        // Une suggestion n'est pas une orientation.
        $this->assertSame(0, EpisodeOrientation::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'episode.next_steps.update')->exists());
    }

    // ── 3–4. Visibilité croisée, dans les deux sens ──────────────────────

    public function test_a_consultation_suggested_for_medicine_is_still_seen_by_care(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);

        $this->confirm($episode, 'CONSULT-GEN', [ReceptionNextStep::Medicine->value])->assertSessionHasNoErrors();

        $this->assertSame([$episode->uuid], $this->uuids($this->doctor(), '/medicine?view=suggested'));
        $this->assertSame([$episode->uuid], $this->uuids($this->nurse(), '/care'));
        $this->assertSame([$episode->uuid], $this->uuids($this->midwife(), '/maternity'));
    }

    // ── 5–7. Regarder ne crée rien ───────────────────────────────────────

    public function test_looking_at_the_boards_creates_no_orientation_consultation_or_record(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-GEN', [ReceptionNextStep::Medicine->value, ReceptionNextStep::Care->value]);

        foreach (['/care' => $this->nurse(), '/medicine' => $this->doctor(), '/maternity' => $this->midwife()] as $url => $user) {
            foreach (['', '?view=suggested', '?view=waiting', '?view=in_progress'] as $view) {
                $this->actingAs($user)->get($url.$view)->assertOk();
            }
        }
        $this->actingAs($this->receptionist())->get("/passages/{$episode->uuid}")->assertOk();

        $this->assertSame(0, EpisodeOrientation::query()->count());
        $this->assertSame(0, Consultation::query()->count());
        $this->assertSame(0, CareRecord::query()->count());
        $this->assertSame(0, MaternityRecord::query()->count());
    }

    // ── 8–9. Prise en charge réelle, Médecine et Soins ───────────────────

    public function test_medicine_takes_the_passage_for_real_and_a_second_doctor_is_told_who_has_it(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-GEN');
        $doctor = $this->doctor();

        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode))->assertRedirect();

        $orientation = EpisodeOrientation::query()->sole();
        $this->assertSame(CatalogModule::Reception, $orientation->source_module);
        $this->assertSame(CatalogModule::Medicine, $orientation->destination_module);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->status);
        $this->assertSame($doctor->id, $orientation->accepted_by);
        // La consultation naît de la prise en charge, pas du regard.
        $this->assertSame(1, Consultation::query()->where('episode_orientation_id', $orientation->id)->count());
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->actingAs($this->doctor())->post(route('medicine.passages.take-charge', $episode))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'déjà en consultation'));
        $this->assertSame(1, EpisodeOrientation::query()->count());
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_care_takes_the_passage_for_real_and_accepts_a_waiting_orientation_instead_of_duplicating_it(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($episode, 'INJ-IM');
        $nurse = $this->nurse();
        $waiting = app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Medicine, CatalogModule::Care, $this->doctor(), 'Soin demandé par le médecin.');

        $this->assertSame('REQUESTED', $this->row($nurse, '/care', $episode)['module']['state']);

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))
            ->assertRedirect(route('care.orientations.show', $waiting));

        $this->assertSame(1, EpisodeOrientation::query()->count());
        $this->assertSame(EpisodeOrientationStatus::InProgress, $waiting->fresh()->status);
        $this->assertSame($nurse->id, $waiting->fresh()->accepted_by);
        // La fiche Soins naît de la première saisie, pas de la prise en charge.
        $this->assertSame(0, CareRecord::query()->count());
        $this->assertSame('IN_PROGRESS', $this->row($nurse, '/care?view=in_progress', $episode)['module']['state']);
    }

    public function test_a_service_that_has_finished_cannot_take_the_passage_again_by_mistake(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($episode, 'INJ-IM');
        $nurse = $this->nurse();
        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode));
        EpisodeOrientation::query()->sole()->complete($nurse);

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))->assertSessionHasErrors('episode');

        $this->assertSame(1, EpisodeOrientation::query()->count());
        $this->assertNull($this->row($nurse, '/care?view=completed', $episode)['actions']['take_charge_url']);
    }

    /** Ce qui est terminé se relit : journal et dossier du passage, chacun selon son droit. */
    public function test_a_finished_passage_offers_its_journal_and_medical_record_by_permission(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($episode, 'INJ-IM');
        $nurse = $this->nurse();

        $waiting = $this->row($nurse, '/care', $episode);
        $this->assertNull($waiting['actions']['journal_url']);
        $this->assertNull($waiting['actions']['medical_record_url']);

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode));
        EpisodeOrientation::query()->sole()->complete($nurse);

        $done = $this->row($nurse, '/care?view=completed', $episode);
        $this->assertSame(route('passages.treatment-journal.show', $episode), $done['actions']['journal_url']);
        $this->assertSame(route('passages.medical-record.print', $episode), $done['actions']['medical_record_url']);
        $this->assertSame(route('passages.show', $episode), $done['actions']['passage_url']);

        // Sans le droit du journal, l'adresse n'est pas servie : jamais un bouton qui mène à un refus.
        $nurse->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'treatment_journal.view')->value('id') => ['effect' => 'deny'],
        ]);
        $this->assertNull($this->row($nurse->fresh(), '/care?view=completed', $episode)['actions']['journal_url']);
    }

    // ── 10. Pharmacie seule ──────────────────────────────────────────────

    /** Un passage qui n'attend plus que la Caisse n'est poussé ni aux Soins ni en Médecine. */
    public function test_a_pharmacy_only_passage_is_never_forced_through_care_or_medicine(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $episode->forceFill([
            'service_plan_finalized_at' => now(),
            'administrative_status' => EpisodeAdministrativeStatus::PendingSettlement,
        ])->save();
        EpisodeReceptionNextStep::query()->create(['episode_id' => $episode->id, 'module' => ReceptionNextStep::Pharmacy]);

        $this->assertSame([], $this->uuids($this->nurse(), '/care'));
        $this->assertSame([], $this->uuids($this->doctor(), '/medicine'));
        $this->assertSame(0, EpisodeOrientation::query()->count());
    }

    // ── 11–13. Droits : voir sans lire, prendre selon le droit ───────────

    public function test_a_board_row_carries_no_clinical_nor_financial_data(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-GEN');
        CareRecord::query()->create([
            'episode_id' => $episode->id,
            'temperature_celsius' => '38.9',
            'blood_pressure_systolic' => 150,
            'blood_pressure_diastolic' => 95,
            'transmission_reason' => 'Fièvre depuis trois jours',
            'created_by' => $this->nurse()->id,
            'updated_by' => $this->nurse()->id,
        ]);

        $json = json_encode($this->row($this->doctor(), '/medicine', $episode));

        foreach (['38.9', 'Fièvre', 'temperature', 'blood_pressure', 'diagnos', 'allerg', 'amount', 'unit_price', '20000'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "{$forbidden} ne doit pas quitter le dossier");
        }
    }

    public function test_seeing_a_board_and_taking_charge_are_gated_by_their_own_permissions(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($episode, 'INJ-IM');
        $pharmacist = $this->user('PHARMACY');

        foreach (['/care', '/medicine', '/maternity'] as $url) {
            $this->actingAs($pharmacist)->get($url)->assertForbidden();
        }
        // Le médecin corrige une fiche Soins (ADR-093) mais ne prend pas un patient aux Soins (ADR-157).
        $this->actingAs($this->doctor())->post(route('care.passages.take-charge', $episode))->assertForbidden();
        $this->actingAs($this->nurse())->post(route('medicine.passages.take-charge', $episode))->assertForbidden();
        $this->assertSame(0, EpisodeOrientation::query()->count());
    }

    public function test_the_next_steps_are_corrected_from_the_passage_audited_and_never_required(): void
    {
        $episode = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($episode, 'INJ-IM', [ReceptionNextStep::Care->value]);
        $url = route('reception.passages.next-steps.update', $episode);
        $receptionist = $this->receptionist();

        $this->actingAs($receptionist)->put($url, ['next_steps' => ['MEDICINE', 'PHARMACY']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['MEDICINE', 'PHARMACY'], $this->steps($episode));

        $audit = AuditLog::query()->where('action', 'episode.next_steps.update')->latest('id')->first();
        $this->assertSame(['CARE'], $audit->old_values['next_steps']);
        $this->assertSame(['MEDICINE', 'PHARMACY'], $audit->new_values['next_steps']);

        // Rien n'est une réponse valide, et ne change rien d'autre.
        $this->actingAs($receptionist)->put($url, ['next_steps' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->steps($episode));
        $this->assertSame(0, EpisodeOrientation::query()->count());

        // Rien de changé : aucune trace fantôme.
        $count = AuditLog::query()->where('action', 'episode.next_steps.update')->count();
        $this->actingAs($receptionist)->put($url, ['next_steps' => []])->assertSessionHasNoErrors();
        $this->assertSame($count, AuditLog::query()->where('action', 'episode.next_steps.update')->count());

        $this->actingAs($receptionist)->put($url, ['next_steps' => ['BLOC']])->assertSessionHasErrors('next_steps.0');
        $this->actingAs($this->nurse())->put($url, ['next_steps' => ['CARE']])->assertForbidden();

        $episode->forceFill(['status' => EpisodeStatus::Closed])->save();
        $this->actingAs($receptionist)->put($url, ['next_steps' => ['CARE']])->assertSessionHasErrors('next_steps');
    }

    // ── 14. Urgence : l'exception assumée ────────────────────────────────

    public function test_an_emergency_keeps_its_real_orientations_on_both_boards(): void
    {
        $patient = $this->patient();
        $episode = app(CreateEpisodeAction::class)->execute($patient, EpisodePriority::Emergency);

        foreach (['/care' => $this->nurse(), '/medicine' => $this->doctor()] as $url => $user) {
            $this->assertSame([$episode->uuid], $this->uuids($user, "{$url}?view=emergency"));
            $this->assertSame([$episode->uuid], $this->uuids($user, "{$url}?view=waiting"));
            $this->assertSame('REQUESTED', $this->row($user, $url, $episode)['module']['state']);
        }
        $this->assertSame(2, EpisodeOrientation::query()->where('status', EpisodeOrientationStatus::Pending->value)->count());
    }

    // ── 15. Les vraies orientations restent ce qu'elles sont ─────────────

    public function test_a_real_orientation_arrives_as_a_request_with_its_source_and_queue_number(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-GEN');
        app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Care, CatalogModule::Medicine, $this->nurse(), 'Transmission des Soins.');

        $row = $this->row($this->doctor(), '/medicine?view=waiting', $episode);

        $this->assertSame('REQUESTED', $row['module']['state']);
        $this->assertSame('Soins', $row['module']['source_label']);
        $this->assertSame(1, $row['module']['queue_number']);
        $this->assertSame('PENDING', $row['status']);
    }

    // ── Aides ────────────────────────────────────────────────────────────

    private function arrival(string $code, ReceptionRoutingMode $route, CatalogModule $module): Episode
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

        return app(SetEpisodeFinancialContextAction::class)->execute($episode, EpisodeFinancialMode::Self, [], $reception);
    }

    /** @param list<string>|null $nextSteps */
    private function confirm(Episode $episode, string $code, ?array $nextSteps = null)
    {
        return $this->actingAs($this->receptionist())->post(route('reception.passages.services.store', $episode), array_filter([
            'catalog_lines' => [['catalog_item_uuid' => CatalogItem::query()->where('code', $code)->value('uuid'), 'quantity' => 1]],
            'payment_choice' => 'LATER',
            'next_steps' => $nextSteps,
        ], fn ($value) => $value !== null));
    }

    /** @return list<string> */
    private function uuids(User $user, string $url): array
    {
        return collect($this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['passages']['data'])->pluck('uuid')->all();
    }

    /** @return array<string, mixed>|null */
    private function row(User $user, string $url, Episode $episode): ?array
    {
        return collect($this->actingAs($user)->get($url)->assertOk()->viewData('page')['props']['passages']['data'])
            ->firstWhere('uuid', $episode->uuid);
    }

    /** @return list<string> */
    private function steps(Episode $episode): array
    {
        return EpisodeReceptionNextStep::query()->where('episode_id', $episode->id)->pluck('module')->map->value->all();
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

    private function midwife(): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $user = User::factory()->create(['role_id' => Role::query()->where('code', 'NURSE')->value('id'), 'professional_profile_id' => $profile->id]);
        $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['effect' => 'allow']],
        )->all());

        return $user->fresh();
    }

    private ?User $receptionist = null;

    private ?User $nurse = null;
}
