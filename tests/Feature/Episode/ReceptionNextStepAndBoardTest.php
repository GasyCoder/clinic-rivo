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
use App\Enums\EpisodeMedicalStatus;
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

    // ── 16. Une étape suggérée déjà faite se dit faite ───────────────────

    public function test_a_suggested_step_already_done_is_marked_done_and_a_reopened_one_is_not(): void
    {
        $episode = $this->arrival('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-GEN', [ReceptionNextStep::Care->value, ReceptionNextStep::Medicine->value]);
        $nurse = $this->nurse();

        // Rien n'est fait tant que personne n'a terminé.
        $steps = collect($this->row($this->doctor(), '/medicine', $episode)['next_steps'])->keyBy('value');
        $this->assertFalse($steps['CARE']['done']);
        $this->assertFalse($steps['MEDICINE']['done']);

        // Les Soins terminent et transmettent : Soins est fait, Médecine ne l'est pas.
        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode));
        EpisodeOrientation::query()->sole()->complete($nurse);
        app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Care, CatalogModule::Medicine, $nurse, 'Transmission des Soins.');

        $steps = collect($this->row($this->doctor(), '/medicine', $episode)['next_steps'])->keyBy('value');
        $this->assertTrue($steps['CARE']['done']);
        $this->assertNotNull($steps['CARE']['done_at']);
        $this->assertFalse($steps['MEDICINE']['done']);
        $this->assertNull($steps['MEDICINE']['done_at']);

        // Renvoyé aux Soins par le médecin : l'étape n'est plus « faite » tant qu'il y attend.
        app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Medicine, CatalogModule::Care, $this->doctor(), 'Soin demandé par le médecin.');

        $steps = collect($this->row($this->doctor(), '/medicine', $episode)['next_steps'])->keyBy('value');
        $this->assertFalse($steps['CARE']['done']);
    }

    // ── 17. La Médecine envoie aux Soins un patient venu directement pour elle ──

    public function test_medicine_sends_a_direct_patient_to_care_who_comes_back_after_the_care(): void
    {
        $episode = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-SPE')->assertSessionHasNoErrors();
        $doctor = $this->doctor();
        $nurse = $this->nurse();

        // Venu directement pour le médecin : les Soins peuvent le prendre d'eux-mêmes,
        // mais il ne tient aucune place dans leur file (ADR-177, amendement du 2026-09-27 bis).
        $careWaiting = $this->row($nurse, '/care', $episode);
        $this->assertNotNull($careWaiting['actions']['take_charge_url']);
        $this->assertNull($careWaiting['module']['queue_number']);
        $offer = $this->row($doctor, '/medicine', $episode)['actions'];
        $this->assertSame(route('medicine.passages.send-to-care', $episode), $offer['send_to_care']['url']);
        $this->assertSame('BEFORE', $offer['send_to_care']['moment']);
        $this->assertSame('MEDICINE', $offer['send_to_care']['then']);
        $this->assertTrue($offer['send_to_care']['returns_to_medicine']);
        $this->assertNull($offer['withdraw_care_url']);

        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $episode), ['note' => 'Constantes et poids avant la consultation.'])
            ->assertSessionHasNoErrors();

        $sent = EpisodeOrientation::query()->where('destination_module', CatalogModule::Care)->sole();
        $this->assertSame(EpisodeOrientationStatus::Pending, $sent->status);
        $this->assertSame(CatalogModule::Medicine, $sent->source_module);
        $this->assertStringContainsString('Constantes et poids avant la consultation.', $sent->reason);
        $this->assertStringStartsWith('Envoyé aux Soins par le médecin, avant la consultation.', $sent->reason);
        $this->assertTrue(AuditLog::query()->where('action', 'episode.sent_to_care')->exists());

        // Le patient garde sa place en Médecine ; l'envoi s'annule, il ne se double pas.
        $row = $this->row($doctor, '/medicine', $episode);
        $this->assertSame(1, $row['module']['queue_number']);
        $this->assertNull($row['actions']['send_to_care']);
        $this->assertSame(route('medicine.passages.withdraw-from-care', $episode), $row['actions']['withdraw_care_url']);
        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $episode))->assertSessionHasErrors('episode');

        // Les Soins le prennent comme toute demande, orientée par Médecine.
        $careRow = $this->row($nurse, '/care', $episode);
        $this->assertSame('REQUESTED', $careRow['module']['state']);
        $this->assertSame('Médecine', $careRow['module']['source_label']);
        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))->assertSessionHasNoErrors();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $sent->fresh()->status);

        // Pris par les Soins : l'envoi ne s'annule plus.
        $this->assertNull($this->row($doctor, '/medicine', $episode)['actions']['withdraw_care_url']);
        $this->actingAs($doctor)->post(route('medicine.passages.withdraw-from-care', $episode))->assertSessionHasErrors('episode');

        // Les soins terminés, le patient revient chez le médecin, orienté par les Soins.
        $sent->fresh()->complete($nurse);
        app(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Care, CatalogModule::Medicine, $nurse, 'Transmission des Soins.');
        $back = $this->row($doctor, '/medicine', $episode);
        $this->assertSame('REQUESTED', $back['module']['state']);
        // Les Soins l'ont déjà vu : le renvoyer reste possible, c'est une nouvelle demande.
        $this->assertSame('BEFORE', $back['actions']['send_to_care']['moment']);
        $this->assertSame('MEDICINE', $back['actions']['send_to_care']['then']);
    }

    public function test_sending_to_care_is_withdrawn_while_care_has_not_taken_the_patient(): void
    {
        $episode = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-SPE');
        $doctor = $this->doctor();

        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $episode))->assertSessionHasNoErrors();
        $this->actingAs($doctor)->post(route('medicine.passages.withdraw-from-care', $episode))->assertSessionHasNoErrors();

        // Annulée, jamais supprimée : la ligne reste, et le geste redevient possible.
        $this->assertSame(EpisodeOrientationStatus::Cancelled, EpisodeOrientation::query()->sole()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'episode.sent_to_care.withdraw')->exists());
        $this->assertNotNull($this->row($doctor, '/medicine', $episode)['actions']['send_to_care']);
        // Sans la demande, il redevient « attendu en Médecine » : les Soins le prennent s'ils le décident.
        $careRow = $this->row($this->nurse(), '/care', $episode);
        $this->assertSame('MEDICINE_ONLY', $careRow['pathway']['code']);
        $this->assertNotNull($careRow['actions']['take_charge_url']);
    }

    public function test_medicine_sends_to_care_at_any_moment_and_says_what_follows(): void
    {
        $doctor = $this->doctor();

        // Suggéré aux Soins par l'accueil : le médecin peut en faire une vraie demande.
        $suggested = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($suggested, 'CONSULT-SPE', [ReceptionNextStep::Care->value]);
        $this->assertSame('MEDICINE', $this->row($doctor, '/medicine', $suggested)['actions']['send_to_care']['then']);

        // Venu pour un soin seul : il s'envoie aussi, et le dit — les soins terminent son parcours.
        $injection = $this->arrival('INJ-IM', ReceptionRoutingMode::CareOnly, CatalogModule::Care);
        $this->confirm($injection, 'INJ-IM');
        $offer = $this->row($doctor, '/medicine', $injection)['actions']['send_to_care'];
        $this->assertSame('FINISH', $offer['then']);
        $this->assertFalse($offer['returns_to_medicine']);

        // Pendant la consultation : elle reste ouverte, le patient revient chez le médecin.
        $direct = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($direct, 'CONSULT-SPE');
        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $direct));
        $during = $this->row($doctor, '/medicine?view=in_progress', $direct)['actions']['send_to_care'];
        $this->assertSame('DURING', $during['moment']);
        $this->assertSame('MEDICINE', $during['then']);
        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $direct))->assertSessionHasNoErrors();

        $medicine = EpisodeOrientation::query()->where('episode_id', $direct->id)->where('destination_module', CatalogModule::Medicine)->sole();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $medicine->status);
        $care = EpisodeOrientation::query()->where('episode_id', $direct->id)->where('destination_module', CatalogModule::Care)->sole();
        $this->assertStringStartsWith('Envoyé aux Soins par le médecin, pendant la consultation.', $care->reason);

        // Les Soins le prennent malgré la consultation en cours : c'est une demande adressée à eux.
        $this->actingAs($this->nurse())->post(route('care.passages.take-charge', $direct))->assertSessionHasNoErrors();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->fresh()->status);

        // Déjà aux Soins : rien à envoyer, ni depuis l'écran ni par le serveur.
        $this->assertNull($this->row($doctor, '/medicine?view=in_progress', $direct)['actions']['send_to_care']);
        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $direct))
            ->assertSessionHasErrors(['episode' => 'Les Soins ont déjà ce patient en charge.']);

        // Sans le droit de prendre en Médecine, le geste n'existe pas.
        $other = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($other, 'CONSULT-SPE');
        $this->actingAs($this->nurse())->post(route('medicine.passages.send-to-care', $other))->assertForbidden();
    }

    public function test_after_the_consultation_the_patient_goes_back_in_care_instead_of_waiting_for_settlement(): void
    {
        $doctor = $this->doctor();
        $episode = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($episode, 'CONSULT-SPE');
        $this->actingAs($doctor)->post(route('medicine.passages.take-charge', $episode));

        // La consultation est close : le passage n'attend plus que la Réception.
        EpisodeOrientation::query()->where('episode_id', $episode->id)->sole()->complete($doctor);
        $episode->forceFill(['administrative_status' => EpisodeAdministrativeStatus::PendingSettlement])->save();

        $after = $this->row($doctor, '/medicine?view=completed', $episode)['actions']['send_to_care'];
        $this->assertSame('AFTER', $after['moment']);
        $this->assertSame('FINISH', $after['then']);

        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $episode), ['note' => 'Injection oubliée.'])
            ->assertSessionHasNoErrors();

        // Le patient repart en soins : il quitte « à régler » le temps des soins.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
        $audit = AuditLog::query()->where('action', 'episode.sent_to_care')->sole();
        $this->assertSame('AFTER', $audit->new_values['moment']);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement->value, $audit->old_values['administrative_status']);
        $this->assertSame(EpisodeAdministrativeStatus::InCare->value, $audit->new_values['administrative_status']);
    }

    public function test_a_deceased_patient_or_a_closed_passage_is_never_sent_to_care(): void
    {
        $doctor = $this->doctor();

        $deceased = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($deceased, 'CONSULT-SPE');
        $deceased->forceFill(['medical_status' => EpisodeMedicalStatus::Deceased])->save();
        $this->assertNull($this->row($doctor, '/medicine', $deceased)['actions']['send_to_care']);
        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $deceased))->assertSessionHasErrors('episode');

        $closed = $this->arrival('CONSULT-SPE', ReceptionRoutingMode::MedicineDirect, CatalogModule::Medicine);
        $this->confirm($closed, 'CONSULT-SPE');
        $closed->forceFill(['status' => EpisodeStatus::Closed])->save();
        $this->actingAs($doctor)->post(route('medicine.passages.send-to-care', $closed))->assertSessionHasErrors('episode');

        $this->assertSame(0, EpisodeOrientation::query()->where('destination_module', CatalogModule::Care)->count());
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
