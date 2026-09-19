<?php

namespace Tests\Feature\Guarding;

use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeExitControl;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-116 — le poste de gardiennage : la « Signature Service Sécurité » du
 * ticket de sortie, remplacée par un constat authentifié. Le gardien ne
 * décide jamais une sortie — la Caisse l'a déjà prononcée (ADR-090) — il la
 * constate, une seule fois par passage.
 */
class GuardingControlTest extends TestCase
{
    use RefreshDatabase;

    private function guard(array $names = ['guarding.view', 'guarding.entries.close']): User
    {
        $role = Role::query()->create(['code' => 'SUPPORT-'.uniqid(), 'name' => 'SUPPORT']);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function receptionist(): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION-'.uniqid(), 'name' => 'RECEPTION']);

        foreach (['episodes.settlement.view', 'episodes.administrative_exit'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function episode(User $actor, EpisodeAdministrativeStatus $status = EpisodeAdministrativeStatus::PendingSettlement): Episode
    {
        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Lova',
            'last_name' => 'Andriamahefa',
            'birth_date' => '1995-03-11',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => EpisodeStatus::Open,
            'administrative_status' => $status,
            'started_at' => now()->subHours(3),
            'created_by' => $actor->id,
        ]);
    }

    private function exitEpisode(Episode $episode, User $actor, string $type = 'PAID_CASH'): Episode
    {
        return app(RecordAdministrativeExitAction::class)->execute($episode, [
            'exit_type' => $type,
            'responsible_name' => $type === AdministrativeExitType::DebtValidated->value ? 'Rakoto Jean' : null,
            'responsible_phone' => $type === AdministrativeExitType::DebtValidated->value ? '032 00 000 00' : null,
            'left_at_estimate' => $type === AdministrativeExitType::Escaped->value ? now()->subMinute()->format('Y-m-d H:i:s') : null,
        ], $actor);
    }

    public function test_a_paid_exit_appears_in_the_queue_to_control(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->exitEpisode($this->episode($receptionist), $receptionist);

        $guard = $this->guard();
        $rows = $this->actingAs($guard)->get('/guarding')->viewData('page')['props']['toControl'];

        $this->assertCount(1, $rows);
        $this->assertSame($episode->uuid, $rows[0]['uuid']);
        $this->assertSame('DISCHARGED_PAID', $rows[0]['administrative_status']);
    }

    public function test_recording_the_exit_moves_it_to_controlled(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->exitEpisode($this->episode($receptionist), $receptionist);
        $guard = $this->guard();

        $this->actingAs($guard)->post("/guarding/passages/{$episode->uuid}/sortie", [
            'notes' => 'Bagages vérifiés.',
        ])->assertSessionHasNoErrors();

        $control = EpisodeExitControl::query()->sole();
        $this->assertSame($episode->id, $control->episode_id);
        $this->assertSame($guard->id, $control->controlled_by);
        $this->assertSame('Bagages vérifiés.', $control->notes);

        $props = $this->actingAs($guard)->get('/guarding')->viewData('page')['props'];
        $this->assertCount(0, $props['toControl']);
        $this->assertCount(1, $props['controlled']);
        $this->assertSame($guard->name, $props['controlled'][0]['exit_control']['controlled_by']);
    }

    /** Un seul contrôle par passage : un second serait un doublon, jamais une correction. */
    public function test_a_passage_is_controlled_only_once(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->exitEpisode($this->episode($receptionist), $receptionist);
        $guard = $this->guard();

        $this->actingAs($guard)->post("/guarding/passages/{$episode->uuid}/sortie", [])->assertSessionHasNoErrors();
        $this->actingAs($guard)->post("/guarding/passages/{$episode->uuid}/sortie", [])->assertSessionHasErrors('episode');

        $this->assertSame(1, EpisodeExitControl::query()->count());
    }

    /**
     * Un évadé est déjà parti sans passer la porte : le gardien ne peut pas
     * constater un départ qu'il n'a jamais vu.
     */
    public function test_an_escaped_exit_cannot_be_controlled_at_the_gate(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->episode($receptionist);

        // Une évasion n'est légale que sur un compte non soldé (§34.1) :
        // sans reste à payer, ce n'est pas ce cas que le test vérifie.
        Invoice::create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_number' => fake()->unique()->bothify('MF-######'),
            'status' => 'VALIDATED',
            'currency' => 'MGA',
            'subtotal_amount' => '8000.00',
            'total_amount' => '8000.00',
            'paid_amount' => '0.00',
            'balance_amount' => '8000.00',
            'created_by' => $receptionist->id,
        ]);

        $episode = $this->exitEpisode($episode, $receptionist, AdministrativeExitType::Escaped->value);
        $guard = $this->guard();

        $this->actingAs($guard)->post("/guarding/passages/{$episode->uuid}/sortie", [])
            ->assertSessionHasErrors('episode');

        $this->assertSame(0, EpisodeExitControl::query()->count());
    }

    /** Un passage encore en cours de soins n'a rien à faire ici. */
    public function test_a_passage_not_yet_exited_cannot_be_controlled(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->episode($receptionist, EpisodeAdministrativeStatus::PendingSettlement);
        $guard = $this->guard();

        $this->actingAs($guard)->post("/guarding/passages/{$episode->uuid}/sortie", [])
            ->assertSessionHasErrors('episode');
    }

    public function test_recording_an_exit_requires_the_close_permission(): void
    {
        $receptionist = $this->receptionist();
        $episode = $this->exitEpisode($this->episode($receptionist), $receptionist);
        $viewOnly = $this->guard(['guarding.view']);

        $this->actingAs($viewOnly)->post("/guarding/passages/{$episode->uuid}/sortie", [])->assertForbidden();
        $this->assertSame(0, EpisodeExitControl::query()->count());
    }

    public function test_the_post_is_hidden_without_guarding_view(): void
    {
        $noAccess = User::factory()->create(['role_id' => Role::query()->create(['code' => 'NOROLE3', 'name' => 'NOROLE3'])->id]);

        $this->actingAs($noAccess)->get('/guarding')->assertForbidden();
    }
}
