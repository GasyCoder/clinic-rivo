<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The two local test accounts, and nothing else.
 *
 *   clinic deployment  → user@rivo.test        (one operational account)
 *   admin deployment   → superadmin@rivo.test  (one central account)
 *
 * The password is deliberately `password`: requested by the owner for local
 * testing only. It bypasses the password policy because it is written here,
 * never through the account screens, and this seeder refuses to run outside
 * local/testing (ADR-022 development exception).
 *
 * The operational account keeps the RECEPTION role and receives, as explicit
 * individual ALLOW rows (ADR-033), the permissions of every operational role
 * plus the reference-data provisioning ones — so one login can walk a patient
 * through Réception, Soins, Médecine, Pharmacie and Caisse, and the catalogue
 * seeders have a real, traceable author.
 */
class DevelopmentTestAccountSeeder extends Seeder
{
    use LocalOnly;

    public const PASSWORD = 'password';

    private const PROVISIONING_PERMISSIONS = [
        'catalog.items.view', 'catalog.items.create', 'catalog.items.update',
        'catalog.tariffs.view', 'catalog.tariffs.create', 'catalog.tariffs.update',
        'analysis_catalog.view', 'analysis_catalog.create', 'analysis_catalog.update',
        // ADR-098 — suppliers and procurement belong to no role, so the local
        // test account receives them by name to walk the whole supply chain.
        'medicine_suppliers.view', 'medicine_suppliers.create',
        'supplier_catalogs.view', 'supplier_catalogs.create', 'supplier_catalogs.update',
        'supplier_catalogs.delete', 'supplier_catalogs.restore',
        'medicine_supplier_offers.view', 'medicine_supplier_offers.create', 'medicine_supplier_offers.update',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.submit', 'purchase_orders.cancel',
        'goods_receipts.view', 'goods_receipts.create',
        'supplier_invoices.view', 'supplier_invoices.create',
        'supplier_invoices.update', 'supplier_invoices.delete', 'supplier_invoices.restore',
    ];

    public function run(): void
    {
        $this->ensureLocal();

        match (config('rivo.site.type')) {
            'admin' => $this->account('superadmin@rivo.test', 'Super Admin test', 'SUPER_ADMIN'),
            'clinic' => $this->clinicAccount(),
            default => throw new LogicException('Déploiement inconnu : RIVO_SITE_TYPE doit valoir clinic ou admin.'),
        };
    }

    /**
     * Created once, then left to the people who manage it.
     *
     * An account that already exists keeps its role, its password and every
     * individual decision taken since — above all a DENY set from the portal.
     * Re-running `migrate:fresh --seed` recreates it from scratch anyway; a
     * plain `db:seed` on a live local database must never silently give back
     * access someone deliberately removed (ADR-022, ADR-033).
     */
    private function clinicAccount(): void
    {
        if (User::query()->where('email', 'user@rivo.test')->exists()) {
            $this->ensureProvisioningAccess(User::query()->where('email', 'user@rivo.test')->firstOrFail());

            return;
        }

        $user = $this->account('user@rivo.test', 'Utilisateur test', 'RECEPTION');

        $fromRoles = Role::query()
            ->where('code', '!=', 'SUPER_ADMIN')
            ->with('permissions:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('id'));

        $user->permissions()->sync(
            $fromRoles->merge($this->provisioningPermissionIds())->unique()
                ->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])
                ->all(),
        );
    }

    private function account(string $email, string $name, string $roleCode): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            return $existing;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'active' => true,
        ])->save();

        return $user;
    }

    /**
     * The catalogue seeders need a traceable author with these rights. Only
     * missing rows are added: an explicit decision already on the account —
     * allow or deny — is never touched.
     */
    private function ensureProvisioningAccess(User $user): void
    {
        $decided = $user->permissions()->pluck('permissions.id');

        $user->permissions()->attach(
            $this->provisioningPermissionIds()
                ->diff($decided)
                ->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])
                ->all(),
        );
    }

    /** @return Collection<int, int> */
    private function provisioningPermissionIds(): Collection
    {
        return Permission::query()->whereIn('name', self::PROVISIONING_PERMISSIONS)->pluck('id');
    }
}
