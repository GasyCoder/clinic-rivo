<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-197 — l'accès du personnel : le Super Admin crée d'un geste le compte RIVO
 * et l'adresse pro d'un employé, avec un seul mot de passe, puis les remet au RH.
 *
 *  - `staff_access_handovers` / `_items` (site) : ce qui est remis au RH. Le mot de
 *    passe y est chiffré le temps de la remise, puis effacé (au plus tard 7 jours) ;
 *  - `employees.access_waived_*` (site) : « aucun accès nécessaire », réversible ;
 *  - `staff_access_notices` (portail) : les employés déjà signalés au Super Admin,
 *    pour ne jamais le notifier deux fois du même ajout ;
 *  - trois droits. Un site en production ne rejoue pas RolePermissionSeeder (ADR-064).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'staff_access.view' => 'Voir les employés qui attendent leur accès (compte et adresse)',
        'staff_access.create' => 'Créer l’accès d’un employé (compte et adresse) et l’envoyer au RH',
        'staff_access.receive' => 'Recevoir et remettre les accès créés pour le personnel (mots de passe)',
    ];

    public function up(): void
    {
        Schema::create('staff_access_handovers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('created_by_uuid')->nullable();
            $table->string('created_by_name', 150)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->uuid('sent_by_uuid')->nullable();
            $table->string('sent_by_name', 150)->nullable();
            // Au plus tard ici, les mots de passe sont effacés.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('first_revealed_at')->nullable();
            $table->foreignId('first_revealed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purged_at')->nullable();
            $table->string('purge_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'purged_at']);
        });

        Schema::create('staff_access_handover_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('staff_access_handover_id')->constrained('staff_access_handovers')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_name', 200);
            $table->string('employee_number', 60)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('login_email');
            $table->string('mailbox_address')->nullable();
            $table->string('role_label', 150);
            $table->string('profile_label', 150)->nullable();
            // Chiffré (clé de l'application), effacé à la remise ou à l'échéance.
            $table->text('secret')->nullable();
            $table->boolean('mailbox_shares_password')->default(false);
            $table->timestamps();

            $table->unique(['staff_access_handover_id', 'employee_id'], 'staff_access_items_employee_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('access_waived_at')->nullable()->after('photo_updated_at');
            $table->string('access_waived_reason', 500)->nullable()->after('access_waived_at');
            $table->string('access_waived_by_name', 150)->nullable()->after('access_waived_reason');
        });

        Schema::create('staff_access_notices', function (Blueprint $table) {
            $table->id();
            $table->string('site_code', 20);
            $table->uuid('employee_uuid');
            $table->timestamp('noticed_at');
            $table->timestamps();

            $table->unique(['site_code', 'employee_uuid']);
        });

        $now = now();
        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Un site : le RH reçoit les accès. Le portail : le Super Administrateur reçoit
        // tout par la synchronisation de l'ADR-186, qui suit chaque `migrate`.
        if (config('rivo.site.type') !== 'admin') {
            $receiveId = DB::table('permissions')->where('name', 'staff_access.receive')->value('id');

            foreach (DB::table('roles')->where('code', 'ADMINISTRATION')->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $receiveId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);

        Schema::dropIfExists('staff_access_notices');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['access_waived_at', 'access_waived_reason', 'access_waived_by_name']);
        });
        Schema::dropIfExists('staff_access_handover_items');
        Schema::dropIfExists('staff_access_handovers');
    }
};
