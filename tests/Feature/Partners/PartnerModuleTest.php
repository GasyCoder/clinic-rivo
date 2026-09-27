<?php

namespace Tests\Feature\Partners;

use App\Enums\PartnerCategory;
use App\Enums\PartnerProfession;
use App\Models\AddressEntry;
use App\Models\AuditLog;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-211 — le module Partenaires : un partenaire Médical (une personne) ou
 * Autre (un organisme ou une personne). Rien n'est supprimé ; un nom n'est
 * jamais porté deux fois ; le même module s'ouvre au site et au portail.
 */
class PartnerModuleTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGE = [
        'partner_organizations.view', 'partner_organizations.create', 'partner_organizations.update',
        'partner_organizations.archive', 'partner_organizations.restore',
    ];

    public function test_the_module_lists_partners_for_whoever_can_see_them(): void
    {
        $viewer = $this->user(['partner_organizations.view']);
        PartnerOrganization::query()->create(['name' => 'ISPSG', 'active' => true]);

        $this->actingAs($viewer)->get('/partenaires')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Partners/Index')
                ->has('partners', 1)
                ->where('partners.0.name', 'ISPSG')
                ->where('partners.0.category', 'OTHER')
                ->has('categories', 2)
                ->has('professions'));

        $this->actingAs($this->user([]))->get('/partenaires')->assertForbidden();

        // Voir n'est pas gérer.
        $this->actingAs($viewer)->post('/partenaires', ['category' => 'OTHER', 'name' => 'TsaraShop'])->assertForbidden();
    }

    public function test_a_medical_partner_is_a_person_with_a_profession(): void
    {
        $manager = $this->user([...self::MANAGE, 'address_entries.view']);
        $address = AddressEntry::query()->create(['label' => 'Tsaramandroso', 'active' => true]);

        $this->actingAs($manager)->post('/partenaires', ['category' => 'MEDICAL', 'last_name' => 'Rabe'])
            ->assertSessionHasErrors('profession');
        $this->actingAs($manager)->post('/partenaires', ['category' => 'MEDICAL', 'last_name' => 'Rabe', 'profession' => 'OTHER'])
            ->assertSessionHasErrors('profession_detail');

        $this->actingAs($manager)->post('/partenaires', [
            'category' => 'MEDICAL',
            'last_name' => '  Rabe ',
            'first_name' => 'Hery',
            'profession' => 'DOCTOR',
            'sex' => 'M',
            'birth_date' => '1975-06-01',
            'phone' => '032 00 000 01',
            'address_entry_uuid' => $address->uuid,
            // Le champ de l'autre catégorie est ignoré.
            'name' => 'Nom qui ne compte pas',
        ])->assertSessionHasNoErrors();

        $partner = PartnerOrganization::query()->sole();
        $this->assertSame(PartnerCategory::Medical, $partner->category);
        $this->assertSame('Rabe Hery', $partner->name);
        $this->assertSame(PartnerProfession::Doctor, $partner->profession);
        $this->assertSame('1975-06-01', $partner->birth_date->toDateString());
        $this->assertSame($address->id, $partner->address_entry_id);
        $this->assertSame('Tsaramandroso', $partner->address);
    }

    /**
     * L'adresse vient du référentiel d'adresses du site (ADR-042), comme pour un
     * employé ou un patient : jamais un texte libre à côté de lui.
     */
    public function test_the_address_comes_from_the_site_referential(): void
    {
        $manager = $this->user([...self::MANAGE, 'address_entries.view', 'address_entries.create']);
        $archived = AddressEntry::query()->create(['label' => 'Ancien quartier', 'active' => true]);
        $archived->forceFill(['active' => false, 'delete_reason' => 'Doublon'])->save();
        $archived->delete();

        // Un texte libre n'est pas une adresse : il n'est pas enregistré.
        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER', 'name' => 'ISPSG', 'address' => 'Texte libre'])
            ->assertSessionHasNoErrors();
        $this->assertNull(PartnerOrganization::query()->where('name', 'ISPSG')->sole()->address);

        // Une nouvelle adresse rejoint le référentiel, une seule fois.
        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER', 'name' => 'TsaraShop', 'new_address_label' => '  Mahabibo  '])
            ->assertSessionHasNoErrors();
        $tsaraShop = PartnerOrganization::query()->where('name', 'TsaraShop')->sole();
        $this->assertSame('Mahabibo', $tsaraShop->address);
        $this->assertSame(1, AddressEntry::query()->where('label', 'Mahabibo')->count());
        $this->assertSame($tsaraShop->address_entry_id, AddressEntry::query()->where('label', 'Mahabibo')->value('id'));

        $this->actingAs($manager)->get('/partenaires')->assertInertia(fn ($page) => $page
            ->has('addresses', 1)
            ->where('addresses.0.label', 'Mahabibo')
            ->where('partners.1.address_entry_uuid', $tsaraShop->addressEntry->uuid));

        // Les deux à la fois : on choisit.
        $this->actingAs($manager)->post('/partenaires', [
            'category' => 'OTHER', 'name' => 'BOA', 'address_entry_uuid' => $tsaraShop->addressEntry->uuid, 'new_address_label' => 'Tanambao',
        ])->assertSessionHasErrors('new_address_label');

        // Une adresse archivée ne se choisit plus.
        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER', 'name' => 'BNI', 'address_entry_uuid' => $archived->uuid])
            ->assertSessionHasErrors(['address_entry_uuid' => 'Cette adresse n’est plus disponible.']);

        // Omettre l'adresse la laisse telle quelle ; l'envoyer vide l'efface.
        $this->actingAs($manager)->put("/partenaires/{$tsaraShop->uuid}", ['category' => 'OTHER', 'name' => 'TsaraShop', 'phone' => '034 00 000 01'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Mahabibo', $tsaraShop->refresh()->address);
        $this->actingAs($manager)->put("/partenaires/{$tsaraShop->uuid}", ['category' => 'OTHER', 'name' => 'TsaraShop', 'address_entry_uuid' => null, 'new_address_label' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($tsaraShop->refresh()->address_entry_id);
        $this->assertNull($tsaraShop->address);
    }

    public function test_choosing_or_adding_an_address_needs_its_own_right(): void
    {
        $address = AddressEntry::query()->create(['label' => 'Tsaramandroso', 'active' => true]);

        $this->actingAs($this->user(self::MANAGE))
            ->post('/partenaires', ['category' => 'OTHER', 'name' => 'ISPSG', 'address_entry_uuid' => $address->uuid])
            ->assertForbidden();

        $this->actingAs($this->user([...self::MANAGE, 'address_entries.view']))
            ->post('/partenaires', ['category' => 'OTHER', 'name' => 'ISPSG', 'new_address_label' => 'Mahabibo'])
            ->assertForbidden();

        // Sans le droit de lire le référentiel, l'écran ne reçoit aucune adresse.
        $this->actingAs($this->user(self::MANAGE))->get('/partenaires')
            ->assertInertia(fn ($page) => $page->has('addresses', 0));
    }

    public function test_another_partner_is_known_by_a_name_or_an_identity(): void
    {
        $manager = $this->user(self::MANAGE);

        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER'])->assertSessionHasErrors('name');

        $this->actingAs($manager)->post('/partenaires', [
            'category' => 'OTHER',
            'name' => 'ISPSG',
            'last_name' => 'Ignoré',
            'profession' => 'DOCTOR',
            'phone' => '034 11 111 11',
        ])->assertSessionHasNoErrors();

        $partner = PartnerOrganization::query()->sole();
        $this->assertSame(PartnerCategory::Other, $partner->category);
        $this->assertSame('ISPSG', $partner->name);
        $this->assertNull($partner->last_name);
        $this->assertNull($partner->profession);
    }

    public function test_a_name_is_never_carried_twice_archives_included(): void
    {
        $manager = $this->user(self::MANAGE);
        $existing = PartnerOrganization::query()->create(['name' => 'ISPSG', 'active' => true]);

        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER', 'name' => 'ispsg'])
            ->assertSessionHasErrors(['name' => 'Un partenaire s’appelle déjà « ISPSG ».']);

        $this->actingAs($manager)->delete("/partenaires/{$existing->uuid}", ['reason' => 'Convention terminée'])
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted($existing);
        $this->assertSame('Convention terminée', $existing->refresh()->delete_reason);

        $this->actingAs($manager)->post('/partenaires', ['category' => 'OTHER', 'name' => 'ISPSG'])
            ->assertSessionHasErrors(['name' => 'Un partenaire archivé s’appelle déjà « ISPSG » : restaurez-le depuis le filtre « Archivés ».']);

        $this->actingAs($manager)->post("/partenaires/{$existing->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($existing);
    }

    public function test_archiving_needs_a_reason_and_a_linked_record_stays_medical(): void
    {
        $manager = $this->user(self::MANAGE);
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-0901',
            'first_name' => 'Lova',
            'last_name' => 'Rabe',
            'birth_date' => '1980-01-01',
            'sex' => 'F',
        ]);
        $partner = PartnerOrganization::query()->create([
            'category' => PartnerCategory::Medical,
            'last_name' => 'Rabe',
            'profession' => PartnerProfession::Nurse,
            'name' => '',
            'patient_id' => $patient->id,
            'active' => true,
        ]);

        $this->actingAs($manager)->delete("/partenaires/{$partner->uuid}", ['reason' => ''])->assertSessionHasErrors('reason');

        $this->actingAs($manager)->put("/partenaires/{$partner->uuid}", ['category' => 'OTHER', 'name' => 'Rabe'])
            ->assertSessionHasErrors('category');

        $this->actingAs($manager)->put("/partenaires/{$partner->uuid}", [
            'category' => 'MEDICAL', 'last_name' => 'Rabe', 'first_name' => 'Lova', 'profession' => 'NURSE', 'active' => false,
        ])->assertSessionHasNoErrors();

        $partner->refresh();
        $this->assertSame('Rabe Lova', $partner->name);
        $this->assertFalse($partner->active);
        $this->assertSame($patient->id, $partner->patient_id);
        $this->assertTrue($partner->isForceDeleteProtected());
    }

    public function test_the_portal_manages_the_partners_of_a_site_through_its_api(): void
    {
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        $this->withHeaders($this->portalHeaders(['partner_organizations.view']))
            ->getJson('/api/v1/super-admin/site-partners')
            ->assertOk()
            ->assertJsonPath('component', 'Partners/Index');

        $this->withHeaders([...$this->portalHeaders(['partner_organizations.view']), 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/super-admin/site-partners', ['category' => 'OTHER', 'name' => 'TsaraShop'])
            ->assertForbidden();

        $this->withHeaders([...$this->portalHeaders(self::MANAGE), 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/super-admin/site-partners', ['category' => 'OTHER', 'name' => 'TsaraShop'])
            ->assertOk();

        $partner = PartnerOrganization::query()->where('name', 'TsaraShop')->sole();
        $audit = AuditLog::query()->where('entity_id', $partner->id)->where('action', 'create')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f', $audit->external_actor_uuid);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'ADMINISTRATION'], ['name' => 'Administration']);
        $role->permissions()->sync(collect($permissions)->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name])->id));

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @param list<string> $permissions */
    private function portalHeaders(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => '6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f',
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
