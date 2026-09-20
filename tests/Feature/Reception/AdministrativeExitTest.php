<?php

namespace Tests\Feature\Reception;

use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\BillableItemStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientDebt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CDC §33.3 — sortie administrative, et la créance qu'elle laisse quand le
 * compte n'est pas soldé.
 */
class AdministrativeExitTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names, string $roleCode = 'RECEPTION'): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function receptionist(array $extra = []): User
    {
        return $this->userWithPermissions([
            'episodes.settlement.view', 'episodes.administrative_exit', 'billing.view',
            ...$extra,
        ]);
    }

    private function episode(
        User $actor,
        EpisodeAdministrativeStatus $status = EpisodeAdministrativeStatus::PendingSettlement,
    ): Episode {
        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1992-04-14',
            'sex' => 'F',
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

    private function invoice(Episode $episode, User $actor, string $total, string $paid): Invoice
    {
        return Invoice::create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_number' => fake()->unique()->bothify('MF-######'),
            'status' => bccomp($paid, '0.00', 2) === 0 ? InvoiceStatus::Validated : InvoiceStatus::PartiallyPaid,
            'currency' => 'MGA',
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => bcsub($total, $paid, 2),
            'created_by' => $actor->id,
        ]);
    }

    /** Une prestation transmise par un service et pas encore portée sur une facture. */
    private function pendingItem(Episode $episode, User $actor, string $amount = '15000.00', string $label = 'Numération formule sanguine (NFS)'): BillableItem
    {
        return BillableItem::create([
            'episode_id' => $episode->id,
            'source_module' => 'LABORATORY',
            'description' => $label,
            'quantity' => '1.00',
            'unit_price' => $amount,
            'total_amount' => $amount,
            'gross_amount' => $amount,
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'patient_amount' => $amount,
            'currency' => 'MGA',
            'status' => BillableItemStatus::Pending,
            'created_by' => $actor->id,
        ]);
    }

    private function exit(User $actor, Episode $episode, array $data): Episode
    {
        return app(RecordAdministrativeExitAction::class)->execute($episode, $data, $actor);
    }

    public function test_a_settled_account_exits_as_paid_cash_and_closes_the_passage(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '25000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Compte soldé, patient sorti.',
        ]);

        $this->assertSame(EpisodeAdministrativeStatus::DischargedPaid, $exited->administrative_status);
        $this->assertSame(AdministrativeExitType::PaidCash, $exited->administrative_exit_type);
        $this->assertSame(EpisodeStatus::Closed, $exited->status);
        $this->assertNotNull($exited->ended_at);
        $this->assertSame($actor->id, $exited->administrative_exit_by);
        $this->assertSame('0.00', $exited->administrative_exit_balance);
        // §34.2 rule 8 — no debt is fabricated for a settled account.
        $this->assertSame(0, PatientDebt::query()->count());
    }

    /* ── Motif composé par le serveur ──────────────────────────────── */

    /**
     * §34.1 règle 8 exige que la sortie porte sa cause. Elle n'exige pas
     * qu'un agent la tape : laissé vide, le motif est composé des chiffres
     * que la transaction vient de recalculer sous verrou.
     */
    public function test_an_omitted_reason_is_composed_from_the_locked_account(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '50000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
        ]);

        $this->assertSame(
            'Compte soldé : 50000.00 facturés, 50000.00 réglés. Sortie prononcée après vérification du compte.',
            $exited->administrative_exit_reason,
        );
    }

    /** Ce que l'agent écrit l'emporte : lui seul connaît les circonstances. */
    public function test_a_written_reason_is_never_replaced(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '50000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Patient reparti avec sa famille après vérification.',
        ]);

        $this->assertSame(
            'Patient reparti avec sa famille après vérification.',
            $exited->administrative_exit_reason,
        );
    }

    /** Le motif généré nomme le responsable réellement enregistré. */
    public function test_the_generated_reason_for_a_debt_names_the_responsible_payer(): void
    {
        $actor = $this->receptionist(['debts.authorize']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '20000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::DebtValidated->value,
            'responsible_name' => 'RAKOTO Jean',
            'responsible_phone' => '034 00 000 00',
        ]);

        $this->assertSame(
            'Dérogation autorisée : reste à payer 30000.00 sur 50000.00 facturés, pris en charge par RAKOTO Jean.',
            $exited->administrative_exit_reason,
        );
    }

    public function test_the_generated_reason_for_an_escape_states_what_remains_due(): void
    {
        $actor = $this->receptionist(['debts.record_escape']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '20000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::Escaped->value,
            'left_at_estimate' => now()->subHour()->toDateTimeString(),
            'last_known_service' => 'Médecine',
        ]);

        $this->assertSame(
            'Départ constaté sans règlement régulier : reste à payer 30000.00 sur 50000.00 facturés, dernier service connu : Médecine.',
            $exited->administrative_exit_reason,
        );
    }

    /** Le motif généré part aussi à l'audit : la trace n'est jamais muette. */
    public function test_the_generated_reason_reaches_the_audit_trail(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '50000.00');

        $this->actingAs($actor);
        $this->exit($actor, $episode, ['exit_type' => AdministrativeExitType::PaidCash->value]);

        $entry = AuditLog::query()->where('action', 'episode.administrative_exit')->sole();

        $this->assertStringContainsString('Compte soldé', (string) $entry->reason);
    }

    public function test_paid_cash_is_refused_while_a_balance_remains(): void
    {
        // §34.1 rule 6 / §34.1 rule 11 — the normal exit depends on the
        // financial status, and the operator cannot declare it settled.
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '10000.00');

        $this->actingAs($actor);
        $this->expectException(ValidationException::class);

        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Le patient dit avoir payé.',
        ]);
    }

    public function test_a_debt_exit_is_refused_when_the_account_is_already_settled(): void
    {
        $actor = $this->receptionist(['debts.authorize']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '25000.00');

        $this->actingAs($actor);
        $this->expectException(ValidationException::class);

        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::DebtValidated->value,
            'reason' => 'Dette de complaisance',
            'responsible_name' => 'Jean Rakoto',
            'responsible_phone' => '0341234567',
        ]);
    }

    public function test_a_validated_debt_creates_an_attributable_receivable(): void
    {
        $actor = $this->receptionist(['debts.authorize']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '10000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::DebtValidated->value,
            'reason' => 'Sortie autorisée, règlement sous 30 jours.',
            'responsible_name' => 'Jean Rakoto',
            'responsible_phone' => '0341234567',
            'responsible_relationship' => 'Époux',
            'due_date' => now()->addDays(30)->toDateString(),
            'comment' => 'Employeur informé.',
        ]);

        $this->assertSame(EpisodeAdministrativeStatus::DischargedDebt, $exited->administrative_status);
        $this->assertSame('15000.00', $exited->administrative_exit_balance);

        $debt = PatientDebt::query()->firstOrFail();
        $this->assertSame('15000.00', $debt->amount);
        $this->assertSame(AdministrativeExitType::DebtValidated, $debt->origin);
        $this->assertSame('Jean Rakoto', $debt->responsible_name);
        $this->assertSame($actor->id, $debt->authorized_by);
        $this->assertSame($episode->id, $debt->episode_id);
        $this->assertNotNull($debt->debt_number);
    }

    public function test_recording_a_debt_exit_requires_the_authorisation_permission(): void
    {
        // §34.1 rule 6 — waiving a balance is a derogation, not an ordinary
        // desk act. An agent who may record exits still cannot grant one.
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '0.00');

        $this->actingAs($actor);
        $this->expectException(AuthorizationException::class);

        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::DebtValidated->value,
            'reason' => 'Sortie autorisée',
            'responsible_name' => 'Jean Rakoto',
            'responsible_phone' => '0341234567',
        ]);
    }

    public function test_an_escape_keeps_the_receivable_and_names_no_responsible_payer(): void
    {
        // §34.2 rule 9 — une évasion ne doit jamais supprimer la créance.
        $actor = $this->receptionist(['debts.record_escape']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '40000.00', '5000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::Escaped->value,
            'reason' => 'Patient introuvable après la consultation.',
            'left_at_estimate' => now()->subHour()->toDateTimeString(),
            'last_known_service' => 'Médecine',
        ]);

        $this->assertSame(EpisodeAdministrativeStatus::DischargedEscaped, $exited->administrative_status);

        $debt = PatientDebt::query()->firstOrFail();
        $this->assertSame('35000.00', $debt->amount);
        $this->assertSame(AdministrativeExitType::Escaped, $debt->origin);
        $this->assertNull($debt->responsible_name);
        $this->assertNull($debt->authorized_by);
        $this->assertSame('Médecine', $debt->last_known_service);
        $this->assertSame($actor->id, $debt->recorded_by);
    }

    public function test_a_receivable_can_never_be_deleted(): void
    {
        $actor = $this->receptionist(['debts.record_escape']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '40000.00', '5000.00');
        $this->actingAs($actor);
        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::Escaped->value,
            'reason' => 'Patient parti sans régler.',
            'left_at_estimate' => now()->subHour()->toDateTimeString(),
        ]);

        $this->expectException(\LogicException::class);
        PatientDebt::query()->firstOrFail()->delete();
    }

    public function test_a_passage_still_in_care_cannot_be_closed_administratively(): void
    {
        // §33.3 — the administrative exit comes after the medical one.
        $actor = $this->receptionist();
        $episode = $this->episode($actor, EpisodeAdministrativeStatus::InCare);
        $this->invoice($episode, $actor, '25000.00', '25000.00');

        $this->actingAs($actor);
        $this->expectException(ValidationException::class);

        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Le patient veut partir.',
        ]);
    }

    public function test_a_passage_is_never_exited_twice(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '25000.00');
        $this->actingAs($actor);
        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Compte soldé.',
        ]);

        $this->expectException(ValidationException::class);
        $this->exit($actor, $episode->fresh(), [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Deuxième tentative.',
        ]);
    }

    public function test_the_exit_is_audited_with_its_reason(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '25000.00');

        $this->actingAs($actor);
        $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Compte soldé, sortie normale.',
        ]);

        $entry = AuditLog::query()->where('action', 'episode.administrative_exit')->sole();
        $this->assertSame($actor->id, $entry->user_id);
        $this->assertSame('Compte soldé, sortie normale.', $entry->reason);
        $this->assertSame('reception', $entry->module);
        $this->assertSame($episode->uuid, $entry->entity_uuid);

        // One decision, one entry: the generic Auditable `update` hook must
        // not shadow it with a second, reason-less row.
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'update')
            ->where('entity_uuid', $episode->uuid)
            ->count());
    }

    public function test_a_cancelled_invoice_is_not_counted_as_a_debt(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '25000.00');
        $cancelled = $this->invoice($episode, $actor, '10000.00', '0.00');
        $cancelled->forceFill([
            'status' => InvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Erreur de saisie',
        ])->save();

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, [
            'exit_type' => AdministrativeExitType::PaidCash->value,
            'reason' => 'Compte soldé après annulation de la facture erronée.',
        ]);

        $this->assertSame(EpisodeAdministrativeStatus::DischargedPaid, $exited->administrative_status);
    }

    public function test_the_settlement_board_lists_passages_awaiting_settlement(): void
    {
        $actor = $this->receptionist();
        $waiting = $this->episode($actor);
        $this->invoice($waiting, $actor, '25000.00', '10000.00');
        $inCare = $this->episode($actor, EpisodeAdministrativeStatus::InCare);

        $this->actingAs($actor)
            ->get('/reception/sorties')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Settlements/Index')
                ->where('counts.pending', 1)
                ->where('episodes.data.0.episode_number', $waiting->episode_number)
                ->where('episodes.data.0.account.balance_amount', '15000.00')
                ->where('capabilities.can_authorize_debt', false)
                ->count('episodes.data', 1));

        $this->assertNotNull($inCare);
    }

    public function test_the_board_hides_amounts_without_billing_view(): void
    {
        // ADR-054 — a section is guarded by the permission that owns its
        // data, not by the one that opens the route.
        $actor = $this->userWithPermissions(['episodes.settlement.view']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '0.00');

        $this->actingAs($actor)
            ->get('/reception/sorties')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_view_accounts', false)
                ->where('episodes.data.0.account', null));
    }

    public function test_the_board_is_closed_without_its_permission(): void
    {
        $actor = $this->userWithPermissions(['episodes.view']);

        $this->actingAs($actor)->get('/reception/sorties')->assertForbidden();
    }

    public function test_the_exit_endpoint_refuses_an_account_without_the_permission(): void
    {
        $actor = $this->userWithPermissions(['episodes.settlement.view', 'billing.view']);
        $episode = $this->episode($actor);

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/sortie-administrative", [
                'exit_type' => AdministrativeExitType::PaidCash->value,
                'reason' => 'Compte soldé.',
            ])
            ->assertForbidden();
    }

    public function test_a_validated_debt_requires_its_responsible_payer(): void
    {
        // §33.3 "informations obligatoires".
        $actor = $this->receptionist(['debts.authorize']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '25000.00', '0.00');

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/sortie-administrative", [
                'exit_type' => AdministrativeExitType::DebtValidated->value,
                'reason' => 'Sortie autorisée.',
            ])
            ->assertSessionHasErrors(['responsible_name', 'responsible_phone']);
    }

    /* ── ADR-090 (amendement du 2026-09-20) : rien ne part sans être facturé ── */

    /**
     * Le cas constaté : une première facture soldée, puis des prestations
     * ajoutées après son règlement. « Reste à payer = 0 » était vrai de la
     * facture, faux du compte du patient — et « payé comptant » laissait partir
     * 70 000 Ar qui ne seraient jamais réclamés.
     */
    public function test_no_exit_is_recorded_while_a_prestation_is_still_unbilled(): void
    {
        // Même avec le droit de dérogation : ce n'est pas un défaut d'habilitation.
        $actor = $this->receptionist(['debts.authorize', 'debts.record_escape']);

        foreach (AdministrativeExitType::cases() as $type) {
            $episode = $this->episode($actor);
            $this->invoice($episode, $actor, '50000.00', '50000.00');
            $this->pendingItem($episode, $actor, '15000.00');
            $this->pendingItem($episode, $actor, '55000.00', 'Échographie et injection');

            $this->actingAs($actor);

            try {
                $this->exit($actor, $episode, [
                    'exit_type' => $type->value,
                    'reason' => 'Test',
                    'responsible_name' => 'Rakoto',
                ]);
                $this->fail("La sortie {$type->value} aurait dû être refusée.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('2 prestations (70 000 Ar)', $exception->errors()['exit_type'][0]);
            }

            $episode->refresh();
            $this->assertSame(EpisodeStatus::Open, $episode->status);
            $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->administrative_status);
        }

        $this->assertSame(0, PatientDebt::query()->count());
    }

    public function test_the_refusal_names_a_single_prestation_in_the_singular(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '10000.00', '10000.00');
        $this->pendingItem($episode, $actor, '5000.00', 'Injection IM');

        $this->actingAs($actor);

        try {
            $this->exit($actor, $episode, ['exit_type' => AdministrativeExitType::PaidCash->value, 'reason' => 'Test']);
            $this->fail('Refus attendu.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1 prestation (5 000 Ar) n’a pas encore été portée sur une facture', $exception->errors()['exit_type'][0]);
        }
    }

    /** Facturer lève le refus : l'écran affiche alors le vrai reste à payer. */
    public function test_invoicing_the_pending_prestations_lifts_the_refusal_and_reveals_the_real_balance(): void
    {
        $actor = $this->receptionist(['billing.create', 'billing.validate']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '50000.00');
        $item = $this->pendingItem($episode, $actor, '15000.00');

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/facturer-prestations")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(BillableItemStatus::Invoiced, $item->fresh()->status);

        $invoice = Invoice::query()->where('episode_id', $episode->id)->where('total_amount', '15000.00')->firstOrFail();
        $this->assertSame(InvoiceStatus::Validated, $invoice->status);

        // Plus rien d'en attente : la sortie n'est plus refusée pour ce motif,
        // mais le compte n'est plus soldé — « payé comptant » l'est désormais
        // pour la bonne raison.
        try {
            $this->exit($actor, $episode, ['exit_type' => AdministrativeExitType::PaidCash->value, 'reason' => 'Test']);
            $this->fail('Le solde de 15 000 Ar aurait dû interdire « payé comptant ».');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('reste à payer', $exception->errors()['exit_type'][0]);
            $this->assertStringNotContainsString('portée', $exception->errors()['exit_type'][0]);
        }
    }

    public function test_without_billing_validate_the_invoice_stays_a_draft(): void
    {
        $actor = $this->receptionist(['billing.create']);
        $episode = $this->episode($actor);
        $this->pendingItem($episode, $actor, '15000.00');

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/facturer-prestations")
            ->assertSessionHasNoErrors();

        $this->assertSame(
            InvoiceStatus::Draft,
            Invoice::query()->where('episode_id', $episode->id)->firstOrFail()->status,
        );
    }

    public function test_the_invoicing_endpoint_needs_billing_create(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $item = $this->pendingItem($episode, $actor);

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/facturer-prestations")
            ->assertForbidden();

        $this->assertSame(BillableItemStatus::Pending, $item->fresh()->status);
    }

    public function test_the_invoicing_endpoint_refuses_a_passage_that_is_no_longer_pending_settlement(): void
    {
        $actor = $this->receptionist(['billing.create']);
        $episode = $this->episode($actor, EpisodeAdministrativeStatus::InCare);
        $this->pendingItem($episode, $actor);

        $this->actingAs($actor)
            ->post("/reception/passages/{$episode->uuid}/facturer-prestations")
            ->assertSessionHasErrors('exit_type');

        $this->assertSame(0, Invoice::query()->where('episode_id', $episode->id)->count());
    }

    public function test_the_board_offers_the_invoicing_action_only_to_billing_create(): void
    {
        $with = $this->receptionist(['billing.create']);
        $without = $this->userWithPermissions(['episodes.settlement.view', 'episodes.administrative_exit', 'billing.view'], 'RECEPTION_LIMITED');

        $this->assertTrue($this->actingAs($with)->get('/reception/sorties')
            ->assertOk()->viewData('page')['props']['capabilities']['can_invoice']);
        $this->assertFalse($this->actingAs($without)->get('/reception/sorties')
            ->assertOk()->viewData('page')['props']['capabilities']['can_invoice']);
    }

    /**
     * ADR-090 (amendement du 2026-09-20) — déclarer un patient évadé crée une
     * créance à son nom : comme la dette validée, c'est un droit que le Super
     * Administrateur accorde, pas une conséquence du droit de prononcer une
     * sortie ordinaire.
     */
    public function test_an_escape_requires_its_own_permission(): void
    {
        $actor = $this->receptionist(); // peut prononcer une sortie, pas déclarer une évasion
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '8000.00', '0.00');

        $this->actingAs($actor);

        try {
            $this->exit($actor, $episode, [
                'exit_type' => AdministrativeExitType::Escaped->value,
                'reason' => 'Parti sans payer.',
                'left_at_estimate' => now()->subHour()->format('Y-m-d H:i:s'),
            ]);
            $this->fail('Le droit debts.record_escape aurait dû être exigé.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('debts.record_escape', $exception->getMessage());
        }

        $episode->refresh();
        $this->assertSame(EpisodeStatus::Open, $episode->status);
        $this->assertSame(0, PatientDebt::query()->count());
    }

    public function test_paid_cash_is_unaffected_by_the_escape_permission(): void
    {
        $actor = $this->receptionist();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '8000.00', '8000.00');

        $this->actingAs($actor);
        $exited = $this->exit($actor, $episode, ['exit_type' => AdministrativeExitType::PaidCash->value, 'reason' => 'Soldé.']);

        $this->assertSame(EpisodeAdministrativeStatus::DischargedPaid, $exited->administrative_status);
    }

    public function test_the_board_tells_the_screen_who_may_record_an_escape(): void
    {
        $with = $this->receptionist(['debts.record_escape']);
        $without = $this->userWithPermissions(['episodes.settlement.view', 'episodes.administrative_exit', 'billing.view'], 'RECEPTION_NO_ESCAPE');

        $this->assertTrue($this->actingAs($with)->get('/reception/sorties')
            ->assertOk()->viewData('page')['props']['capabilities']['can_record_escape']);
        $this->assertFalse($this->actingAs($without)->get('/reception/sorties')
            ->assertOk()->viewData('page')['props']['capabilities']['can_record_escape']);
    }
}
