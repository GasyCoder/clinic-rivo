<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADR-009 / CDC §11: the three columns every SoftDeletable model
        // needs, declared once so they can never drift between migrations.
        Blueprint::macro('softDeletesWithReason', function () {
            /** @var Blueprint $this */
            $this->softDeletes();
            $this->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $this->text('delete_reason')->nullable();
        });

        // Every dynamic "resource.action" permission resolves through this
        // callback without needing a Gate::define() per permission — adding
        // a new Permission row makes it immediately checkable everywhere
        // (Policies, `can:` middleware, $user->can()) with no code change.
        //
        // Even SUPER_ADMIN resolves through persisted role permissions. There
        // is no role-name bypass, and an explicit individual DENY remains
        // prioritary (ADR-007/027).
        Gate::before(function (User $user, string $ability) {
            // role_id is protected by a foreign key; a non-null value is a
            // valid local role, without an extra query on every Gate check.
            if (! $user->isActive() || ! $user->role_id) {
                return false;
            }

            if (! Permission::allNames()->contains($ability)) {
                return null;
            }

            return $user->hasPermissionTo($ability);
        });

        // ADR-098 — a supplier price needs .create the first time and .update
        // afterwards; SetMedicineSupplierOfferAction decides which applies.
        // A route can only require one ability, hence this "either" gate.
        // ADR-098 — « Médicaments & stock » is one page: the catalog view is
        // enough to open it, stock columns follow stock.view.
        Gate::define('view-pharmacy-catalog', fn (User $user): bool => $user->can('stock.view') || $user->can('medicines.view'));

        // ADR-098 — « Achats » opens on whichever of its tabs the account may see.
        Gate::define('view-pharmacy-purchases', fn (User $user): bool => $user->can('purchase_orders.view')
            || $user->can('goods_receipts.view')
            || $user->can('supplier_invoices.view'));

        // ADR-098 — « Voir les fournisseurs » opens a whole supplier folder in
        // read-only mode; every write keeps its own permission.
        foreach ([
            'view-supplier-catalogs' => 'supplier_catalogs.view',
            'view-supplier-orders' => 'purchase_orders.view',
            'view-supplier-invoices' => 'supplier_invoices.view',
            'view-supplier-offers' => 'medicine_supplier_offers.view',
        ] as $ability => $permission) {
            Gate::define($ability, fn (User $user): bool => $user->can($permission) || $user->can('medicine_suppliers.view'));
        }

        Gate::define('set-medicine-supplier-offer', fn (User $user): bool => $user->can('medicine_supplier_offers.create')
            || $user->can('medicine_supplier_offers.update'));
    }
}
