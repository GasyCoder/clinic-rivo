<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Une désignation se crée et se modifie sur sa propre page, plus dans une
 * fenêtre (ADR-044, amendement du 2026-09-28). Le motif d'un tarif peut être
 * automatique : c'est alors le portail qui l'écrit, jamais le navigateur.
 */
class TariffItemPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.test', 'api_url' => null, 'api_token' => null],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_the_create_page_reads_only_the_form_options_and_keeps_the_category_it_came_from(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/catalog/options' => Http::response(['data' => ['options' => $this->formOptions()]], 200)]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs/items/create?site=A&module=IMAGING:ULTRASOUND')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('targetSite.code', 'A')
                ->where('targetSite.name', 'Ambondromamy')
                ->where('item', null)
                ->where('category', 'IMAGING:ULTRASOUND')
                ->where('options.modules.0.value', 'MEDICINE')
                ->where('siteError', null));

        Http::assertSentCount(1);
        $this->actingAs($this->superAdmin)->get('/super-admin/workspaces/tariffs/items/create?site=Z')->assertNotFound();
    }

    public function test_an_unreachable_site_is_said_on_the_page_instead_of_an_error(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs/items/create?site=B')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('options', null)
                ->whereNot('siteError', null));
    }

    public function test_the_edit_page_shows_the_designation_with_its_tariff_history(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/catalog/item-uuid' => Http::response(['data' => [
                'item' => ['uuid' => 'item-uuid', 'code' => 'ECHO-ABD', 'name' => 'Échographie abdominale', 'module' => 'IMAGING', 'imaging_modality' => 'ULTRASOUND', 'tariffs' => [['uuid' => 't-1', 'tariff_category' => 'STANDARD', 'amount' => '50000.00']]],
                'options' => $this->formOptions(),
            ]], 200),
            'https://a.test/api/v1/super-admin/catalog/missing-uuid' => Http::response(['message' => 'Introuvable.'], 404),
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/tariffs/items/A/item-uuid/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('item.code', 'ECHO-ABD')
                ->where('item.tariffs.0.amount', '50000.00'));

        $this->actingAs($this->superAdmin)->get('/super-admin/workspaces/tariffs/items/A/missing-uuid/edit')->assertNotFound();
    }

    public function test_creating_with_the_automatic_reason_writes_it_on_the_server_and_opens_the_new_category(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/catalog' => Http::response([
            'message' => 'Désignation ECHO-ABD créée sur le site.',
            'data' => ['uuid' => 'new-uuid', 'code' => 'ECHO-ABD', 'module' => 'IMAGING', 'imaging_modality' => 'ULTRASOUND'],
        ], 201)]);

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/items', [
            'site_code' => 'A',
            'code' => 'ECHO-ABD',
            'name' => 'Échographie abdominale',
            'type' => 'SERVICE',
            'module' => 'IMAGING',
            'imaging_modality' => 'ULTRASOUND',
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'tariff_amount' => '50000',
            'tariff_reason_auto' => true,
            // Ce que le navigateur enverrait ne compte pas : le motif automatique est celui du serveur.
            'tariff_reason' => 'texte forgé',
        ])->assertRedirect('/super-admin/workspaces/tariffs?site=A&module=IMAGING%3AULTRASOUND&q=ECHO-ABD');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://a.test/api/v1/super-admin/catalog'
            && $request['tariff_reason'] === 'Tarif initial fixé à la création de la désignation (motif automatique).'
            && ! isset($request['tariff_reason_auto']));
    }

    public function test_a_refused_creation_stays_on_the_page_with_the_site_errors(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/catalog' => Http::response([
            'message' => 'Le code existe déjà.',
            'errors' => ['code' => ['Ce code est déjà utilisé.']],
        ], 422)]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/workspaces/tariffs/items/create?site=A')
            ->post('/super-admin/workspaces/tariffs/items', ['site_code' => 'A', 'code' => 'ECHO-ABD', 'name' => 'X', 'tariff_amount' => '1', 'tariff_reason' => 'Grille'])
            ->assertRedirect('/super-admin/workspaces/tariffs/items/create?site=A')
            ->assertSessionHasErrors(['code' => 'Ce code est déjà utilisé.']);
    }

    public function test_a_tariff_change_with_the_automatic_reason_names_the_grid_and_the_amount(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/catalog/item-uuid/tariffs' => Http::response(['message' => 'Tarif enregistré.', 'data' => []], 200)]);

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/items/A/item-uuid/tariffs', [
            'tariff_category' => 'MUTUAL',
            'tariff_amount' => '25000',
            'reason_auto' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request['reason'] === 'Tarif mutuelle fixé à 25 000 Ar depuis la fiche de la désignation (motif automatique).'
            && $request['tariff_amount'] === '25000');
    }

    public function test_without_the_automatic_reason_a_typed_reason_is_required(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/tariffs/items/A/item-uuid/tariffs', [
            'tariff_category' => 'STANDARD',
            'tariff_amount' => '25000',
            'reason_auto' => false,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        Http::assertNothingSent();
    }

    /**
     * ADR-044, amendement du 2026-09-28 (ter) — le matériel habituel d'un acte
     * se règle aussi depuis le portail, par l'API du site.
     */
    public function test_the_usual_material_of_an_act_is_sent_to_the_site(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/catalog/act-uuid/care-consumables' => Http::response(['message' => 'Matériel habituel de Pansement mis à jour.', 'data' => []], 200),
            'https://a.test/api/v1/super-admin/catalog/act-uuid' => Http::response(['data' => [
                'item' => ['uuid' => 'act-uuid', 'code' => 'PANSEMENT', 'name' => 'Pansement', 'module' => 'CARE', 'accepts_consumables' => true, 'default_consumables' => []],
                'options' => $this->formOptions(),
                'consumable_options' => [['medicine_uuid' => '11111111-1111-4111-8111-111111111111', 'code' => 'PH-1', 'name' => 'Compresses', 'unit' => 'paquet', 'available_quantity' => 4, 'available' => true]],
            ]], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/workspaces/tariffs/items/A/act-uuid/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/ItemForm')
                ->where('context.mode', 'portal')
                ->where('item.accepts_consumables', true)
                ->where('consumableOptions.0.name', 'Compresses'));

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/workspaces/tariffs/items/A/act-uuid/edit')
            ->put('/super-admin/workspaces/tariffs/items/A/act-uuid/care-consumables', [
                'consumables' => [['medicine_uuid' => '11111111-1111-4111-8111-111111111111', 'default_quantity' => 2]],
            ])
            ->assertRedirect('/super-admin/workspaces/tariffs/items/A/act-uuid/edit')
            ->assertSessionHas('status', 'Matériel habituel de Pansement mis à jour.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://a.test/api/v1/super-admin/catalog/act-uuid/care-consumables'
            && $request->hasHeader('Idempotency-Key')
            && $request['consumables'][0]['default_quantity'] === 2);

        // Une quantité impossible ne part pas au site.
        $this->actingAs($this->superAdmin)
            ->put('/super-admin/workspaces/tariffs/items/A/act-uuid/care-consumables', [
                'consumables' => [['medicine_uuid' => '11111111-1111-4111-8111-111111111111', 'default_quantity' => 0]],
            ])
            ->assertSessionHasErrors('consumables.0.default_quantity');
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'types' => [['value' => 'SERVICE', 'label' => 'Prestation', 'billable' => true, 'stockable' => false]],
            'modules' => [['value' => 'MEDICINE', 'label' => 'Médecine'], ['value' => 'IMAGING', 'label' => 'Imagerie']],
            'imaging_modalities' => [['value' => 'ULTRASOUND', 'label' => 'Échographie']],
            'routing_modes' => [],
            'staff_coverage_policies' => [['value' => 'UNCLASSIFIED', 'label' => 'À classifier']],
            'tariff_categories' => [['value' => 'STANDARD', 'label' => 'Sans mutuelle'], ['value' => 'MUTUAL', 'label' => 'Mutuelle']],
        ];
    }
}
