<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Support\SiteApi\RemoteActorPermissions;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le catalogue complet des permissions dépassait, dans un seul en-tête en clair, la
 * taille qu'Apache accepte (8 190 octets) : le site répondait « 400 Bad Request » en
 * HTML et le portail affichait « L'API du site a refusé la requête ».
 */
class RemoteActorPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** LimitRequestFieldSize par défaut d'Apache, nom et valeur de l'en-tête compris. */
    private const APACHE_FIELD_LIMIT = 8190;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    public function test_the_whole_catalogue_travels_in_headers_apache_accepts(): void
    {
        $names = Permission::query()->orderBy('name')->pluck('name');
        $this->assertGreaterThan(self::APACHE_FIELD_LIMIT, strlen($names->implode(',')), 'en clair, le catalogue ne tient plus dans un en-tête');

        $headers = RemoteActorPermissions::headers($names);

        foreach ($headers as $name => $value) {
            $this->assertStringStartsWith(RemoteActorPermissions::CHUNK_HEADER, $name);
            $this->assertLessThan(self::APACHE_FIELD_LIMIT, strlen($name.': '.$value));
        }

        $request = Request::create('/');
        $request->headers->add($headers);
        $this->assertSame($names->implode(','), RemoteActorPermissions::raw($request));
    }

    public function test_the_site_reads_the_compressed_permissions_and_refuses_unreadable_ones(): void
    {
        $everything = Permission::query()->pluck('name');

        $this->withHeaders($this->headers(RemoteActorPermissions::headers($everything)))
            ->getJson('/api/v1/super-admin/staff-debts/overview')
            ->assertOk();

        $this->withHeaders($this->headers(RemoteActorPermissions::headers($everything->reject(fn ($name) => $name === 'staff_debts.view'))))
            ->getJson('/api/v1/super-admin/staff-debts/overview')
            ->assertForbidden();

        $this->withHeaders($this->headers([RemoteActorPermissions::CHUNK_HEADER.'1' => 'pas-une-archive']))
            ->getJson('/api/v1/super-admin/staff-debts/overview')
            ->assertStatus(422)
            ->assertJsonPath('message', 'L’en-tête des permissions de l’acteur distant est illisible.');

        // Un portail pas encore mis à jour envoie l'en-tête en clair : il reste compris.
        $this->withHeaders($this->headers([RemoteActorPermissions::PLAIN_HEADER => 'staff_debts.view']))
            ->getJson('/api/v1/super-admin/staff-debts/overview')
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $permissionHeaders
     * @return array<string, string>
     */
    private function headers(array $permissionHeaders): array
    {
        // withHeaders() garde les en-têtes d'un appel à l'autre : un morceau laissé
        // par l'appel précédent fausserait la liste reçue.
        $this->flushHeaders();

        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction générale',
        ] + $permissionHeaders;
    }
}
