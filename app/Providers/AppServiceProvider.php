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
        // SUPER_ADMIN is granted access here AND holds every permission as
        // explicit role_permissions rows (see RoleSeeder), so this is not a
        // silent bypass. It only short-circuits authorization checks — it
        // must never be used as a substitute for enforcing critical business
        // invariants (e.g. "only Reception/Cash collects payments") which
        // belong in the relevant Actions/Services regardless of role.
        Gate::before(function (User $user, string $ability) {
            if ($user->hasRole('SUPER_ADMIN')) {
                return true;
            }

            if (! Permission::allNames()->contains($ability)) {
                return null;
            }

            return $user->hasPermissionTo($ability);
        });
    }
}
