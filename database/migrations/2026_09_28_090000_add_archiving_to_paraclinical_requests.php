<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-131 — ranger soi-même une demande d'examen lue.
 *
 * « Archivées » ne contenait que ce que le temps ou un retrait y mettait : un
 * médecin ne pouvait pas ranger un résultat qu'il venait de lire. Un simple
 * drapeau daté et signé, réversible — rien n'est supprimé, et l'archivage ne
 * change aucun fait clinique (ni résultat, ni facturation).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lab_requests', 'imaging_requests'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dateTime('archived_at')->nullable();
                $blueprint->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        $permission = ['name' => 'paraclinical_requests.archive', 'label' => 'Archiver et désarchiver une demande d’examen'];

        DB::table('permissions')->updateOrInsert(
            ['name' => $permission['name']],
            $permission + ['created_at' => now(), 'updated_at' => now()],
        );

        // Par migration et non seulement dans le seeder (ADR-064).
        $medicine = DB::table('roles')->where('code', 'MEDICINE')->value('id');
        $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

        if ($medicine !== null && $id !== null) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $medicine, 'permission_id' => $id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        foreach (['lab_requests', 'imaging_requests'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('archived_by');
                $blueprint->dropColumn('archived_at');
            });
        }

        $id = DB::table('permissions')->where('name', 'paraclinical_requests.archive')->value('id');

        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
