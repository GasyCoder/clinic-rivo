<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-116 — fiches papier de la clinique.
 *
 * Deux enregistrements nouveaux, le reste n'étant que des impressions de ce
 * que le dossier contient déjà :
 *
 *   treatment_journal_entries   les lignes saisies à la main du « Dossier
 *                               médical – Traitement » (le reste du journal
 *                               est lu depuis les actes déjà enregistrés)
 *   episode_exit_controls       le contrôle du gardien à la porte, qui
 *                               répond à la ligne « Signature Service
 *                               Sécurité » du ticket de sortie
 */
return new class extends Migration
{
    private const GRANTS = [
        'treatment_journal.view' => ['label' => 'Consulter le journal de traitement d’un passage', 'roles' => ['MEDICINE', 'NURSE']],
        'treatment_journal.record' => ['label' => 'Ajouter une ligne au journal de traitement', 'roles' => ['MEDICINE', 'NURSE']],
    ];

    public function up(): void
    {
        Schema::create('treatment_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->text('description');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['episode_id', 'occurred_at']);
        });

        Schema::create('episode_exit_controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Une seule sortie constatée par passage : un second contrôle
            // serait un doublon, jamais une correction.
            $table->foreignId('episode_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('controlled_at');
            $table->foreignId('controlled_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
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
        Schema::dropIfExists('episode_exit_controls');
        Schema::dropIfExists('treatment_journal_entries');

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
