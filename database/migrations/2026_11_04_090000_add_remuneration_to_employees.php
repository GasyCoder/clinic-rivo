<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-197 — la rémunération déclarée du dossier employé (salaire, indemnité ou
 * rien, et son montant) et son compte bancaire (numéro, titulaire).
 *
 * Données sensibles : deux droits dédiés, accordés au rôle ADMINISTRATION (RH).
 * Un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064), d'où
 * leur enregistrement ici. Aucune paie n'est calculée (ADR-066).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'employees.payroll.view' => 'Voir la rémunération et le compte bancaire d’un employé',
        'employees.payroll.update' => 'Modifier la rémunération et le compte bancaire d’un employé',
    ];

    /** @var list<string> */
    private const ROLES = ['ADMINISTRATION'];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('remuneration_type', 20)->nullable()->after('observation');
            $table->decimal('remuneration_amount', 15, 2)->nullable()->after('remuneration_type');
            $table->string('bank_account_number', 50)->nullable()->after('remuneration_amount');
            $table->string('bank_account_holder', 150)->nullable()->after('bank_account_number');
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('roles')->whereIn('code', self::ROLES)->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['remuneration_type', 'remuneration_amount', 'bank_account_number', 'bank_account_holder']);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
