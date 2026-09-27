<?php

namespace Tests\Feature\Reception;

use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\MedicalDischarge;
use App\Models\Patient;
use App\Models\PatientDebt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-116 — la « FICHE DE SORTIE » de la clinique, imprimable une fois la
 * sortie administrative prononcée (CDC §33.3, ADR-090).
 */
class ExitSlipPrintTest extends TestCase
{
    use RefreshDatabase;

    private function receptionist(array $extra = []): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION-'.uniqid(), 'name' => 'RECEPTION']);

        foreach (['episodes.settlement.view', 'episodes.administrative_exit', 'billing.view', ...$extra] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function episode(User $actor, EpisodeAdministrativeStatus $status = EpisodeAdministrativeStatus::PendingSettlement): Episode
    {
        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Voahangy',
            'last_name' => 'Randria',
            'birth_date' => '1988-05-20',
            'sex' => 'F',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => EpisodeStatus::Open,
            'administrative_status' => $status,
            'started_at' => now()->subHours(5),
            'created_by' => $actor->id,
        ]);
    }

    /** Rien à signer avant que la Caisse n'ait prononcé la sortie. */
    public function test_the_slip_is_not_printable_before_the_administrative_exit(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);

        $this->actingAs($actor)
            ->get("/reception/passages/{$episode->uuid}/sortie-administrative/fiche")
            ->assertNotFound();
    }

    /**
     * Le papier ne connaissait qu'une seule date de sortie ; le dossier
     * distingue la sortie médicale (ADR-035) de la sortie administrative
     * (ADR-090), et la feuille montre les deux.
     */
    public function test_the_slip_shows_both_the_medical_and_administrative_exit(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);

        $consultation = Consultation::query()->create([
            'episode_id' => $episode->id,
            'doctor_id' => $actor->id,
            'reason' => 'Contrôle',
            'consulted_at' => now()->subHours(2),
        ]);

        MedicalDischarge::query()->create([
            'episode_id' => $episode->id,
            'consultation_id' => $consultation->id,
            'type' => MedicalDischargeType::Normal,
            'patient_condition' => 'Stable',
            'discharged_at' => now()->subHour(),
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor)->post("/reception/passages/{$episode->uuid}/sortie-administrative", [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Compte soldé.',
        ])->assertSessionHasNoErrors();

        $props = $this->actingAs($actor)
            ->get("/reception/passages/{$episode->uuid}/sortie-administrative/fiche")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reception/Settlements/ExitSlipPrint'))
            ->viewData('page')['props'];

        $this->assertSame('NORMAL', $props['medical_discharge']['type']);
        $this->assertSame('Sorti — payé comptant', $props['administrative_exit']['type_label']);
        $this->assertSame($actor->name, $props['administrative_exit']['author']);
        $this->assertSame('0.00', $props['administrative_exit']['balance_amount']);
    }

    /** Une dette validée porte sa référence : la famille en aura besoin. */
    public function test_a_debt_exit_carries_its_receivable_number(): void
    {
        $actor = $this->receptionist(['debts.authorize']);
        $episode = $this->episode($actor);

        Invoice::create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_number' => fake()->unique()->bothify('MF-######'),
            'status' => 'VALIDATED',
            'currency' => 'MGA',
            'subtotal_amount' => '15000.00',
            'total_amount' => '15000.00',
            'paid_amount' => '0.00',
            'balance_amount' => '15000.00',
            'created_by' => $actor->id,
        ]);

        app(RecordAdministrativeExitAction::class)->execute($episode, [
            'exit_type' => AdministrativeExitType::DebtValidated->value,
            'responsible_name' => 'Rakoto Jean',
            'responsible_phone' => '032 00 000 00',
        ], $actor);

        $debt = PatientDebt::query()->sole();

        $props = $this->actingAs($actor)
            ->get("/reception/passages/{$episode->uuid}/sortie-administrative/fiche")
            ->viewData('page')['props'];

        $this->assertSame($debt->debt_number, $props['administrative_exit']['debt_number']);
        $this->assertSame('15000.00', $props['administrative_exit']['balance_amount']);
    }

    public function test_the_slip_requires_the_settlement_permission(): void
    {
        $noAccess = User::factory()->create(['role_id' => Role::query()->create(['code' => 'NOROLE2', 'name' => 'NOROLE2'])->id]);
        $actor = $this->receptionist();
        $episode = $this->episode($actor);

        $this->actingAs($actor)->post("/reception/passages/{$episode->uuid}/sortie-administrative", [
            'exit_type' => AdministrativeExitType::PaidCash->value,
        ])->assertSessionHasNoErrors();

        $this->actingAs($noAccess)
            ->get("/reception/passages/{$episode->uuid}/sortie-administrative/fiche")
            ->assertForbidden();
    }
}
