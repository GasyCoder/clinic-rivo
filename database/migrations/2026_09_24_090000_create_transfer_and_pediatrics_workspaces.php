<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-114 — modules Transferts et Pédiatrie.
 *
 * La demande de transfert part en un clic : l'établissement et le motif se
 * complètent dans le module Transferts, qui enregistre aussi le départ réel
 * du patient (« Transfert effectué »).
 */
return new class extends Migration
{
    private const GRANTS = [
        'transfers.view' => ['label' => 'Consulter les patients à transférer et transférés', 'roles' => ['MEDICINE', 'NURSE', 'RECEPTION']],
        'transfers.manage' => ['label' => 'Compléter un transfert et enregistrer le départ du patient', 'roles' => ['MEDICINE', 'NURSE', 'RECEPTION']],
        'pediatrics.view' => ['label' => 'Consulter la file Pédiatrie', 'roles' => ['MEDICINE']],
        'pediatrics.manage' => ['label' => 'Prendre en charge un patient orienté en Pédiatrie', 'roles' => ['MEDICINE']],
    ];

    public function up(): void
    {
        Schema::table('medical_referrals', function (Blueprint $table) {
            $table->string('facility', 255)->nullable()->change();
            $table->text('reason')->nullable()->change();
            $table->timestamp('departed_at')->nullable()->after('referred_at');
            $table->foreignId('departed_by')->nullable()->after('departed_at')->constrained('users')->nullOnDelete();
            $table->text('departure_notes')->nullable()->after('departed_by');
        });

        foreach (self::GRANTS as $name => $grant) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['label' => $grant['label'], 'created_at' => now(), 'updated_at' => now()],
            );
            $permission = DB::table('permissions')->where('name', $name)->value('id');

            // Par migration : un site en production ne rejoue plus
            // `RolePermissionSeeder` (ADR-064).
            foreach ($grant['roles'] as $code) {
                $role = DB::table('roles')->where('code', $code)->value('id');

                if ($role !== null) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $role, 'permission_id' => $permission],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('medical_referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departed_by');
            $table->dropColumn(['departed_at', 'departure_notes']);
        });

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
