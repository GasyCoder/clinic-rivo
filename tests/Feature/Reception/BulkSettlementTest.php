<?php

namespace Tests\Feature\Reception;

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
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Sélection multiple de « Sorties & règlements » : chaque passage est jugé
 * séparément, avec les règles qui le jugent seul (ADR-090).
 */
class BulkSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $roleSeq = 0;

    private function user(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'ROLE_'.++$this->roleSeq, 'name' => 'Role']);

        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name]));
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function clerk(array $extra = []): User
    {
        return $this->user(['episodes.settlement.view', 'episodes.administrative_exit', 'billing.view', ...$extra]);
    }

    private function episode(User $actor, string $last = 'Rakoto'): Episode
    {
        $patient = Patient::create([
            'patient_number' => fake()->unique()->bothify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => $last,
            'birth_date' => '1992-04-14',
            'sex' => 'F',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => EpisodeStatus::Open,
            'administrative_status' => EpisodeAdministrativeStatus::PendingSettlement,
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

    private function pending(Episode $episode, User $actor, string $amount = '15000.00'): BillableItem
    {
        return BillableItem::create([
            'episode_id' => $episode->id,
            'source_module' => 'LABORATORY',
            'description' => 'Numération formule sanguine (NFS)',
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

    /* ── Sortie « payé comptant » en lot ───────────────────────────────── */

    public function test_each_passage_is_judged_separately_and_the_report_says_why(): void
    {
        $actor = $this->clerk();

        $settled = $this->episode($actor, 'Soldé');
        $this->invoice($settled, $actor, '20000.00', '20000.00');

        $owing = $this->episode($actor, 'Redevable');
        $this->invoice($owing, $actor, '20000.00', '5000.00');

        $unbilled = $this->episode($actor, 'Nonfacture');
        $this->invoice($unbilled, $actor, '10000.00', '10000.00');
        $this->pending($unbilled, $actor);

        $response = $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', [
            'episode_uuids' => [$settled->uuid, $owing->uuid, $unbilled->uuid],
        ]);

        $response->assertRedirect();
        $report = session('bulk_report');

        $this->assertSame(3, $report['total']);
        $this->assertSame(1, $report['done']);
        $this->assertCount(2, $report['failed']);

        // Le passage soldé est clos ; les deux autres n'ont pas bougé.
        $this->assertSame(EpisodeAdministrativeStatus::DischargedPaid, $settled->fresh()->administrative_status);
        $this->assertSame(EpisodeStatus::Closed, $settled->fresh()->status);
        $this->assertSame(EpisodeStatus::Open, $owing->fresh()->status);
        $this->assertSame(EpisodeStatus::Open, $unbilled->fresh()->status);

        $reasons = collect($report['failed'])->pluck('message', 'episode_number');
        $this->assertStringContainsString('reste à payer', $reasons[$owing->episode_number]);
        $this->assertStringContainsString('portée', $reasons[$unbilled->episode_number]);
        $this->assertSame('warning', session('status_type'));
    }

    public function test_a_bulk_exit_leaves_one_audit_entry_per_passage_plus_a_summary(): void
    {
        $actor = $this->clerk();
        $first = $this->episode($actor, 'Un');
        $second = $this->episode($actor, 'Deux');
        $this->invoice($first, $actor, '1000.00', '1000.00');
        $this->invoice($second, $actor, '2000.00', '2000.00');

        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', [
            'episode_uuids' => [$first->uuid, $second->uuid],
        ])->assertSessionHas('status_type', 'success');

        $this->assertSame(2, AuditLog::query()->where('action', 'episode.administrative_exit')->count());
        $summary = AuditLog::query()->where('action', 'settlement.bulk_exit')->firstOrFail();
        $this->assertSame(2, $summary->new_values['done']);
    }

    /** Dette validée et évasion exigent un responsable ou un constat : jamais en lot. */
    public function test_the_bulk_exit_can_only_ever_record_paid_cash(): void
    {
        $actor = $this->clerk(['debts.authorize', 'debts.record_escape']);
        $owing = $this->episode($actor);
        $this->invoice($owing, $actor, '9000.00', '0.00');

        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', [
            'episode_uuids' => [$owing->uuid],
            'exit_type' => AdministrativeExitType::Escaped->value,
        ]);

        $episode = $owing->fresh();
        $this->assertSame(EpisodeStatus::Open, $episode->status);
        $this->assertNull($episode->administrative_exit_type);
    }

    public function test_the_selection_is_bounded_and_must_be_uuids(): void
    {
        $actor = $this->clerk();

        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', ['episode_uuids' => []])
            ->assertSessionHasErrors('episode_uuids');
        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', ['episode_uuids' => ['pas-un-uuid']])
            ->assertSessionHasErrors('episode_uuids.0');
        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', [
            'episode_uuids' => array_map(fn () => (string) Str::uuid(), range(1, 51)),
        ])->assertSessionHasErrors('episode_uuids');
    }

    public function test_the_bulk_exit_needs_the_exit_permission(): void
    {
        $actor = $this->user(['episodes.settlement.view', 'billing.view']);
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '1000.00', '1000.00');

        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', ['episode_uuids' => [$episode->uuid]])
            ->assertForbidden();

        $this->assertSame(EpisodeStatus::Open, $episode->fresh()->status);
    }

    /* ── Facturation en lot ────────────────────────────────────────────── */

    public function test_bulk_invoicing_creates_one_invoice_per_passage_and_reports_what_it_skipped(): void
    {
        $actor = $this->clerk(['billing.create', 'billing.validate']);

        $one = $this->episode($actor, 'Un');
        $itemOne = $this->pending($one, $actor, '15000.00');
        $two = $this->episode($actor, 'Deux');
        $itemTwo = $this->pending($two, $actor, '5000.00');
        $nothing = $this->episode($actor, 'Rien');

        $this->actingAs($actor)->post('/reception/sorties/facturation-groupee', [
            'episode_uuids' => [$one->uuid, $two->uuid, $nothing->uuid],
        ])->assertRedirect();

        $report = session('bulk_report');
        $this->assertSame(2, $report['done']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame($nothing->episode_number, $report['failed'][0]['episode_number']);

        $this->assertSame(BillableItemStatus::Invoiced, $itemOne->fresh()->status);
        $this->assertSame(BillableItemStatus::Invoiced, $itemTwo->fresh()->status);
        $this->assertSame(1, Invoice::query()->where('episode_id', $one->id)->count());
        $this->assertSame(1, Invoice::query()->where('episode_id', $two->id)->count());
        $this->assertSame(InvoiceStatus::Validated, Invoice::query()->where('episode_id', $one->id)->firstOrFail()->status);
    }

    public function test_bulk_invoicing_needs_billing_create(): void
    {
        $actor = $this->clerk();
        $episode = $this->episode($actor);
        $item = $this->pending($episode, $actor);

        $this->actingAs($actor)->post('/reception/sorties/facturation-groupee', ['episode_uuids' => [$episode->uuid]])
            ->assertForbidden();

        $this->assertSame(BillableItemStatus::Pending, $item->fresh()->status);
    }

    /* ── Fiches de sortie en lot ───────────────────────────────────────── */

    public function test_exit_slips_are_grouped_and_a_passage_without_exit_is_skipped(): void
    {
        $actor = $this->clerk();
        $closed = $this->episode($actor, 'Sorti');
        $this->invoice($closed, $actor, '1000.00', '1000.00');
        $this->actingAs($actor)->post('/reception/sorties/sortie-groupee', ['episode_uuids' => [$closed->uuid]]);
        $open = $this->episode($actor, 'Encours');

        $props = $this->actingAs($actor)
            ->get('/reception/sorties/fiches?uuids[]='.$closed->uuid.'&uuids[]='.$open->uuid)
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['slips']);
        $this->assertSame($closed->episode_number, $props['slips'][0]['episode']['episode_number']);
        $this->assertSame(1, $props['skipped']);
    }

    /* ── Export Excel ──────────────────────────────────────────────────── */

    private function sheet($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sortie').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        unlink($path);

        return $rows;
    }

    public function test_the_export_lists_the_selection_with_amounts_for_billing_view(): void
    {
        $actor = $this->clerk();
        $episode = $this->episode($actor);
        $this->invoice($episode, $actor, '50000.00', '50000.00');
        $this->pending($episode, $actor, '15000.00');

        $rows = $this->sheet($this->actingAs($actor)->get('/reception/sorties/export?uuids[]='.$episode->uuid)->assertOk());

        $this->assertContains('Reste à payer', $rows[0]);
        $this->assertContains('Non facturé', $rows[0]);
        $this->assertSame($episode->episode_number, $rows[1][2]);
        $this->assertSame('15000.00', $rows[1][array_search('Non facturé', $rows[0], true)]);
        $this->assertSame(1, AuditLog::query()->where('action', 'settlement.export')->count());
    }

    public function test_the_export_carries_no_financial_column_without_billing_view(): void
    {
        $actor = $this->user(['episodes.settlement.view', 'episodes.administrative_exit']);
        $episode = $this->episode($actor);

        $rows = $this->sheet($this->actingAs($actor)->get('/reception/sorties/export?uuids[]='.$episode->uuid)->assertOk());

        foreach (['Facturé', 'Payé', 'Reste à payer', 'Non facturé'] as $column) {
            $this->assertNotContains($column, $rows[0]);
        }
    }

    public function test_the_export_needs_the_board_permission(): void
    {
        $actor = $this->user(['billing.view']);
        $episode = $this->episode($actor);

        $this->actingAs($actor)->get('/reception/sorties/export?uuids[]='.$episode->uuid)->assertForbidden();
    }
}
