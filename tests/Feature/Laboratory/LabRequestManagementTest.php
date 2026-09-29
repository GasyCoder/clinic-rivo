<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\LabItemStatus;
use App\Enums\TrashCategory;
use App\Models\AuditLog;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Trash\TrashDirectory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-220 — ranger, corriger et mettre à la corbeille les demandes d'analyses,
 * une à une ou en lot, sans jamais toucher ce qui a été envoyé au médecin.
 */
class LabRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->doctor = User::factory()->create(['role_id' => Role::query()->where('code', 'MEDICINE')->value('id')]);
        $this->technician = User::factory()->create(['role_id' => Role::query()->where('code', 'LABORATORY')->value('id')]);
    }

    public function test_the_laboratory_archives_and_edits_but_the_trash_belongs_to_nobody_by_default(): void
    {
        $this->assertTrue($this->technician->can('laboratory_orders.archive'));
        $this->assertTrue($this->technician->can('laboratory_orders.update'));
        $this->assertFalse($this->technician->can('laboratory_orders.delete'));
        $this->assertFalse($this->technician->can('laboratory_orders.restore'));
    }

    public function test_only_a_finished_request_is_archived_and_it_leaves_the_queue_for_the_archived_view(): void
    {
        $request = $this->request(['NFS']);

        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/archive")
            ->assertSessionHasErrors('request');

        $this->sendAll($request);
        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/archive")->assertSessionHasNoErrors();
        $this->assertNotNull($request->fresh()->lab_archived_at);
        $this->assertTrue(AuditLog::query()->where('action', 'laboratory.request.archive')->exists());

        $this->actingAs($this->technician)->get('/laboratory?view=validated')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.total', 0)->where('counts.archived', 1));
        $this->actingAs($this->technician)->get('/laboratory?view=archived')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('requests.data.0.uuid', $request->uuid)->where('requests.data.0.archived', true));

        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/unarchive")->assertSessionHasNoErrors();
        $this->assertNull($request->fresh()->lab_archived_at);
    }

    public function test_returning_an_analysis_to_redo_brings_its_archived_request_back_to_the_queue(): void
    {
        $request = $this->request(['NFS']);
        $this->sendAll($request);
        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/archive");

        $this->actingAs($this->technician)->post("/laboratory/items/{$request->items->first()->uuid}/return", ['reason' => 'Valeur à contrôler'])
            ->assertSessionHasNoErrors();

        $this->assertNull($request->fresh()->lab_archived_at);
    }

    public function test_the_worklist_says_which_request_may_go_to_the_trash(): void
    {
        $request = $this->request(['NFS']);
        $request->update(['received_at' => now()]);

        $this->actingAs($this->technician)->get('/laboratory/paillasse')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sheets.0.rows.0.trashable', true)
                ->where('manage.trash', false));
    }

    public function test_the_trash_needs_its_own_right_a_reason_and_releases_the_pending_billing(): void
    {
        $request = $this->request(['NFS', 'Glycémie']);
        $billable = $request->items->first()->billableItem;
        $this->assertSame(BillableItemStatus::Pending, $billable->status);

        $this->actingAs($this->technician)->delete("/laboratory/requests/{$request->uuid}", ['reason' => 'Doublon'])->assertForbidden();

        $admin = $this->withPermissions($this->technician, ['laboratory_orders.delete']);
        $this->actingAs($admin)->delete("/laboratory/requests/{$request->uuid}", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->delete("/laboratory/requests/{$request->uuid}", ['reason' => 'Saisie en double'])
            ->assertRedirect('/laboratory');

        $trashed = LabRequest::withTrashed()->find($request->id);
        $this->assertTrue($trashed->trashed());
        $this->assertSame('Saisie en double', $trashed->delete_reason);
        $this->assertSame(BillableItemStatus::Cancelled, $billable->fresh()->status);
        $this->actingAs($admin)->get("/laboratory/requests/{$request->uuid}")->assertNotFound();
    }

    public function test_a_request_whose_results_were_sent_never_goes_to_the_trash(): void
    {
        $request = $this->request(['NFS']);
        $this->sendAll($request);
        $admin = $this->withPermissions($this->technician, ['laboratory_orders.delete']);

        $this->actingAs($admin)->delete("/laboratory/requests/{$request->uuid}", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('request');
        $this->assertFalse($request->fresh()->trashed());
    }

    public function test_the_trash_lists_the_request_restores_it_bills_it_again_and_never_destroys_it(): void
    {
        $request = $this->request(['NFS']);
        $line = $request->items->first();
        $admin = $this->withPermissions($this->technician, ['laboratory_orders.delete', 'laboratory_orders.restore', 'trash.view', 'trash.restore', 'trash.force_delete']);
        $this->actingAs($admin)->delete("/laboratory/requests/{$request->uuid}", ['reason' => 'Mauvais patient']);

        $directory = app(TrashDirectory::class);
        $listed = collect($directory->list(['category' => TrashCategory::LabRequest->value])['data']);
        $this->assertSame($request->uuid, $listed->first()['uuid']);
        $this->assertFalse($listed->first()['can_force_delete']);

        $this->expectForceDeleteRefused($directory, $request, $admin);

        $directory->restore(TrashCategory::LabRequest, $request->uuid, CatalogActor::fromUser($admin));
        $restored = $request->fresh();
        $this->assertFalse($restored->trashed());

        $again = $line->fresh()->billableItem;
        $this->assertSame(BillableItemStatus::Pending, $again->status);
        $this->assertSame("lab_request_item:{$line->uuid}:1", $again->idempotency_key);
        $this->assertSame(2, BillableItem::query()->where('idempotency_key', 'like', "lab_request_item:{$line->uuid}%")->count());
    }

    public function test_an_analysis_is_added_billed_and_removed_with_a_reason(): void
    {
        $request = $this->request(['NFS']);
        $crp = $this->labCatalogItem('CRP');

        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/items", ['catalog_item_uuids' => [$crp->uuid]])
            ->assertSessionHasNoErrors();
        $added = $request->items()->where('catalog_item_id', $crp->id)->sole();
        $this->assertSame(BillableItemStatus::Pending, $added->billableItem->status);

        $this->actingAs($this->technician)->post("/laboratory/requests/{$request->uuid}/items", ['catalog_item_uuids' => [$crp->uuid]])
            ->assertSessionHasErrors('catalog_item_uuids');

        $this->actingAs($this->technician)->delete("/laboratory/items/{$added->uuid}", ['reason' => 'Non prescrite'])->assertSessionHasNoErrors();
        $this->assertTrue(LabRequestItem::withTrashed()->find($added->id)->trashed());
        $this->assertSame(BillableItemStatus::Cancelled, $added->billableItem->fresh()->status);
        $this->assertSame(1, $request->fresh()->items()->count(), 'une analyse retirée quitte la demande');

        $last = $request->fresh()->items()->sole();
        $this->actingAs($this->technician)->delete("/laboratory/items/{$last->uuid}", ['reason' => 'Plus utile'])
            ->assertSessionHasErrors('item');
    }

    public function test_the_clinical_notes_are_corrected_and_audited(): void
    {
        $request = $this->request(['NFS']);

        $this->actingAs($this->technician)->put("/laboratory/requests/{$request->uuid}/renseignements", ['notes' => 'Fièvre depuis 3 jours'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Fièvre depuis 3 jours', $request->fresh()->notes);
    }

    public function test_the_bulk_judges_each_request_separately_and_reports_the_refusals(): void
    {
        $finished = $this->request(['NFS']);
        $this->sendAll($finished);
        $open = $this->request(['Glycémie']);

        $response = $this->actingAs($this->technician)->post('/laboratory/requests/bulk', [
            'action' => 'archive', 'uuids' => [$finished->uuid, $open->uuid],
        ]);

        $response->assertSessionHas('status_type', 'warning');
        $report = session('bulk_report');
        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['done']);
        $this->assertCount(1, $report['failed']);
        $this->assertNotNull($finished->fresh()->lab_archived_at);
        $this->assertNull($open->fresh()->lab_archived_at);

        $this->actingAs($this->technician)->post('/laboratory/requests/bulk', ['action' => 'trash', 'uuids' => [$open->uuid], 'reason' => 'Doublon'])
            ->assertForbidden();
    }

    public function test_editing_stays_at_the_site_while_archiving_and_the_trash_open_to_the_portal(): void
    {
        $routes = file_get_contents(base_path('routes/laboratory.php'));

        foreach (['requests.notes', 'requests.items.add', 'items.remove'] as $name) {
            $this->assertMatchesRegularExpression("/->name\\('".preg_quote($name, '/')."'\\)[^;]*rivo\\.site-only:laboratory/", $routes);
        }
        foreach (['requests.archive', 'requests.unarchive', 'requests.trash', 'requests.bulk'] as $name) {
            $this->assertDoesNotMatchRegularExpression("/->name\\('".preg_quote($name, '/')."'\\)[^;]*rivo\\.site-only/", $routes);
        }
    }

    private function expectForceDeleteRefused(TrashDirectory $directory, LabRequest $request, User $admin): void
    {
        try {
            $directory->forceDelete(TrashCategory::LabRequest, $request->uuid, CatalogActor::fromUser($admin));
            $this->fail('Une demande d’analyses ne se détruit jamais.');
        } catch (ValidationException) {
            $this->assertNotNull(LabRequest::withTrashed()->find($request->id));
        }
    }

    /** @param  array<int, string>  $permissions */
    private function withPermissions(User $user, array $permissions): User
    {
        $user->permissions()->attach(
            Permission::query()->whereIn('name', $permissions)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['effect' => 'allow']])->all()
        );

        return $user->fresh();
    }

    /** Toutes les analyses de la demande rendues et envoyées au médecin. */
    private function sendAll(LabRequest $request): void
    {
        $request->items()->update(['status' => LabItemStatus::Validated->value, 'resulted_at' => now(), 'validated_at' => now(), 'sent_at' => now()]);
    }

    /** @param  array<int, string>  $analyses */
    private function request(array $analyses): LabRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Soa', 'last_name' => 'Rakoto', 'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient);
        $episode->forceFill(['financial_mode' => EpisodeFinancialMode::Self, 'financial_context_completed_at' => now(), 'financial_context_completed_by' => $this->doctor->id])->save();
        app(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $this->doctor);
        $orientation = $episode->orientations()->where('destination_module', CatalogModule::Medicine->value)->sole();
        app(AcceptMedicineOrientationAction::class)->execute($orientation, $this->doctor);

        $items = collect($analyses)->map(fn (string $name) => ['catalog_item_uuid' => $this->labCatalogItem($name)->uuid])->all();
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", ['items' => $items])
            ->assertSessionHasNoErrors();

        return LabRequest::query()->where('episode_id', $episode->id)->latest('id')->firstOrFail()->load('items.billableItem');
    }

    private function labCatalogItem(string $name): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(), 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory, 'unit' => 'analyse', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id, 'tariff_category' => CatalogTariffCategory::Standard, 'amount' => '12000.00',
            'currency' => 'MGA', 'effective_from' => now(), 'active_key' => 'CURRENT', 'change_reason' => 'Tarif de test ADR-220',
            'created_by' => $this->doctor->id,
        ]);

        return $item;
    }
}
