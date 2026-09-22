<?php

namespace Tests\Feature\Seeders;

use App\Models\AnalysisCatalog;
use App\Models\CashRegister;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\DevelopmentCashRegisterSeeder;
use Database\Seeders\DevelopmentLegacyAnalysisCatalogSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\DevelopmentTestAccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * A fresh local database must be usable right away, and never twice-seeded.
 */
class DevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_a_fresh_clinic_gets_one_test_account_two_desks_and_the_analysis_catalogue(): void
    {
        foreach (range(1, 2) as $run) {
            $this->seed([
                DevelopmentTestAccountSeeder::class,
                DevelopmentCashRegisterSeeder::class,
                DevelopmentLegacyAnalysisCatalogSeeder::class,
            ]);
        }

        $user = User::query()->sole();
        $this->assertSame('user@rivo.test', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->can('episodes.create'));
        $this->assertTrue($user->can('care.complete'));
        $this->assertTrue($user->can('catalog.items.create'));

        $this->assertSame(['Caisse 1', 'Caisse 2'], CashRegister::query()->orderBy('name')->pluck('name')->all());

        // The second run added nothing, and every child still has its parent.
        $this->assertSame(719, AnalysisCatalog::query()->count());
        $this->assertSame(0, AnalysisCatalog::query()->whereNotNull('parent_id')->whereDoesntHave('parent')->count());
    }

    /**
     * ADR-086, rétabli le 2026-09-22 — la Pharmacie se saisit avec de vraies
     * données : un `migrate:fresh --seed` local ne crée ni médicament, ni
     * lot, ni fournisseur. Le stock de démonstration reste appelable à la
     * main (`DevelopmentMedicineStockSeeder`).
     */
    public function test_a_fresh_clinic_gets_no_pharmacy_data(): void
    {
        $this->seed(DevelopmentSeeder::class);

        $this->assertDatabaseCount('medicines', 0);
        $this->assertDatabaseCount('medicine_lots', 0);
        $this->assertDatabaseCount('medicine_suppliers', 0);
    }

    public function test_re_seeding_never_gives_back_access_someone_removed(): void
    {
        $this->seed(DevelopmentTestAccountSeeder::class);
        $user = User::query()->sole();
        $surgeryView = Permission::query()->where('name', 'surgery.view')->firstOrFail();

        // Denied from the portal, then someone runs db:seed again.
        $user->permissions()->updateExistingPivot($surgeryView->id, ['effect' => 'deny']);
        $user->forceFill(['password' => 'Autre-Mot-De-Passe-2026!'])->save();

        $this->seed(DevelopmentTestAccountSeeder::class);

        $user = $user->fresh();
        $this->assertFalse($user->can('surgery.view'));
        $this->assertTrue(Hash::check('Autre-Mot-De-Passe-2026!', $user->password));
        $this->assertTrue($user->can('catalog.items.create'));
    }

    public function test_the_portal_gets_only_the_super_admin(): void
    {
        config(['rivo.site.type' => 'admin']);

        $this->seed(DevelopmentTestAccountSeeder::class);

        $account = User::query()->sole();
        $this->assertSame('superadmin@rivo.test', $account->email);
        $this->assertTrue($account->hasRole('SUPER_ADMIN'));
    }

    public function test_development_data_is_refused_in_production(): void
    {
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);

        $this->seed(DevelopmentTestAccountSeeder::class);
    }
}
