<?php

namespace Tests\Feature\Episode;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransitionException;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeModelTest extends TestCase
{
    use RefreshDatabase;

    private function makePatient(): Patient
    {
        return Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    private function makeEpisode(array $overrides = []): Episode
    {
        return Episode::create([
            'patient_id' => $this->makePatient()->id,
            'episode_number' => 'ME-000001',
            'status' => EpisodeStatus::Open,
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_an_episode_receives_a_uuid_automatically(): void
    {
        $episode = $this->makeEpisode();

        $this->assertNotNull($episode->uuid);
    }

    public function test_emergency_contact_fields_are_stored_on_the_episode(): void
    {
        // ADR-034: the contact reachable for a patient belongs to the
        // passage, not the permanent patient record.
        $episode = $this->makeEpisode([
            'emergency_contact_name' => 'Marie Rakoto',
            'emergency_contact_phone' => '0341234567',
            'emergency_contact_relationship' => 'Épouse',
            'emergency_contact_email' => 'marie.rakoto@example.mg',
        ]);

        $this->assertSame('Marie Rakoto', $episode->emergency_contact_name);
        $this->assertSame('0341234567', $episode->emergency_contact_phone);
        $this->assertSame('Épouse', $episode->emergency_contact_relationship);
        $this->assertSame('marie.rakoto@example.mg', $episode->emergency_contact_email);
    }

    public function test_administrative_status_covers_the_circuit_positions_named_by_the_client_cdcf(): void
    {
        // Client CDCF §36: "En cours de soins" and "En attente de règlement"
        // are named states between orientation and administrative exit —
        // regression guard against silently dropping them again.
        $this->assertSame('IN_CARE', EpisodeAdministrativeStatus::InCare->value);
        $this->assertSame('PENDING_SETTLEMENT', EpisodeAdministrativeStatus::PendingSettlement->value);
        $this->assertSame('DISCHARGED', EpisodeAdministrativeStatus::Discharged->value);
    }

    public function test_status_and_administrative_status_are_cast_to_their_enums(): void
    {
        $episode = $this->makeEpisode();

        $this->assertSame(EpisodeStatus::Open, $episode->status);
        $this->assertSame(EpisodePriority::Normal, $episode->priority);
        $this->assertSame(EpisodeAdministrativeStatus::PendingOrientation, $episode->administrative_status);
    }

    public function test_medical_status_and_financial_status_are_plain_strings_not_cast(): void
    {
        $episode = $this->makeEpisode(['medical_status' => 'EN_CONSULTATION', 'financial_status' => 'IMPAYE']);

        $this->assertSame('EN_CONSULTATION', $episode->medical_status);
        $this->assertSame('IMPAYE', $episode->financial_status);
    }

    public function test_belongs_to_a_patient(): void
    {
        $patient = $this->makePatient();
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => EpisodeStatus::Open,
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
        ]);

        $this->assertTrue($episode->patient->is($patient));
        $this->assertTrue($patient->episodes->first()->is($episode));
    }

    public function test_creating_an_episode_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $episode = $this->makeEpisode();

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', Episode::class)
            ->where('entity_id', $episode->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('reception', $log->module);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_updating_a_field_is_audited_generically(): void
    {
        $episode = $this->makeEpisode();

        $episode->update(['medical_status' => 'EN_CONSULTATION']);

        $log = AuditLog::where('action', 'update')
            ->where('entity_type', Episode::class)
            ->where('entity_id', $episode->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['medical_status' => 'EN_CONSULTATION'], $log->new_values);
    }

    public function test_start_care_transitions_oriented_to_in_care(): void
    {
        $episode = $this->makeEpisode(['administrative_status' => EpisodeAdministrativeStatus::Oriented]);

        $episode->startCare();

        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_start_care_is_a_silent_no_op_once_already_past_in_care(): void
    {
        $episode = $this->makeEpisode(['administrative_status' => EpisodeAdministrativeStatus::PendingSettlement]);

        $episode->startCare();

        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
    }

    public function test_cancel_sets_status_and_ended_at_and_is_audited_once_with_the_reason(): void
    {
        $episode = $this->makeEpisode();

        $episode->cancel('Erreur de saisie');

        $fresh = $episode->fresh();
        $this->assertSame(EpisodeStatus::Cancelled, $fresh->status);
        $this->assertNotNull($fresh->ended_at);

        $this->assertSame(1, AuditLog::where('action', 'cancel')
            ->where('entity_type', Episode::class)
            ->count());
        $this->assertSame(0, AuditLog::where('action', 'update')
            ->where('entity_type', Episode::class)
            ->count());

        $log = AuditLog::where('action', 'cancel')->first();
        $this->assertSame('Erreur de saisie', $log->reason);
    }

    public function test_cancel_refuses_an_already_cancelled_episode(): void
    {
        $episode = $this->makeEpisode();
        $episode->cancel('Première annulation');

        $this->expectException(InvalidEpisodeTransitionException::class);

        $episode->cancel('Deuxième tentative');
    }

    public function test_cancel_refuses_a_closed_episode(): void
    {
        $episode = $this->makeEpisode(['status' => EpisodeStatus::Closed, 'ended_at' => now()]);

        $this->expectException(InvalidEpisodeTransitionException::class);

        $episode->cancel('Trop tard');
    }

    public function test_a_patient_with_episodes_is_force_delete_protected(): void
    {
        $patient = $this->makePatient();
        Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => EpisodeStatus::Open,
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
        ]);

        $this->assertTrue($patient->isForceDeleteProtected());
    }
}
