<?php

namespace App\Providers;

use App\Actions\Role\SyncPortalSuperAdminPermissionsAction;
use App\Models\Permission;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\Settings\SiteMaintenanceState;
use App\Services\Webmail\WebmailAccess;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Les paramètres du site sont lus à chaque page : une seule lecture
        // par requête, jamais d'une requête à l'autre (ADR-184).
        $this->app->scoped(AppSettings::class);
        // ADR-193 — la maintenance du site, lue une fois par requête.
        $this->app->scoped(SiteMaintenanceState::class);
        // ADR-195 — la boîte du titulaire, résolue une fois par requête.
        $this->app->scoped(WebmailAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Les migrations ajoutent des permissions par DB::table() — sans
        // passer par le modèle, donc sans l'événement qui vide le cache des
        // noms connus. Le Gate ignorait alors la nouvelle permission et
        // refusait l'accès (403) à des rôles qui la détenaient pourtant.
        // Vider ce cache à la fin de chaque migration couvre toutes les
        // migrations, passées et futures, sans compter sur chacune d'elles.
        Event::listen(MigrationsEnded::class, fn () => Cache::forget(Permission::CACHE_KEY));

        // ADR-186 — sur le portail, le Super Admin détient toutes les
        // permissions : chaque `php artisan migrate` le rétablit, même sans
        // migration en attente, pour qu'aucun droit ajouté plus tard ne lui
        // manque. Sans effet sur un site clinique.
        Event::listen(
            [MigrationsEnded::class, NoPendingMigrations::class],
            fn () => app(SyncPortalSuperAdminPermissionsAction::class)->execute(),
        );

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
        // ADR-194 — la photo d'un employé se voit partout où son nom se voit
        // déjà dans le module RH : dossier, planning, contrats, présences, congés.
        Gate::define('view-employee-photo', fn (User $user): bool => $user->can('employees.view')
            || $user->can('planning.view') || $user->can('contracts.view')
            || $user->can('attendance.view') || $user->can('leave.view'));
        // ADR-172 — le dossier chirurgical imprimable s'ouvre au bloc comme à l'anesthésie ;
        // chaque feuille reste gardée par son propre droit (SurgicalDossierSheet).
        Gate::define('view-surgical-dossier', fn (User $user): bool => $user->can('surgery.view') || $user->can('anesthesia.view'));

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
