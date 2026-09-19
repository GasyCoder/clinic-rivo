<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-113 — Hospitalisation : le séjour et sa fiche de régime.
 *
 * Le séjour commence dès que le médecin transmet sa demande
 * d'hospitalisation (admission automatique, décision du propriétaire) et se
 * termine par la sortie médicale prononcée par le médecin (CDC §33.1). La
 * fiche de régime suit ce séjour jour par jour, en texte libre, sans aucun
 * montant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_stays', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('hospitalization_request_id')->unique()->constrained('hospitalization_requests')->restrictOnDelete();
            $table->foreignId('episode_orientation_id')->constrained('episode_orientations')->restrictOnDelete();

            $table->string('status', 20);
            // Service demandé par le médecin, repris à l'admission.
            $table->string('service', 150)->nullable();
            // Texte libre, sans gestion d'occupation des lits.
            $table->string('room_bed', 100)->nullable();

            $table->timestamp('admitted_at');
            $table->foreignId('admitted_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('discharged_at')->nullable();
            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('medical_discharge_id')->nullable()->constrained('medical_discharges')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            // Un seul séjour en cours par passage : même verrou nullable-unique
            // que `episode_orientations` et `cash_sessions`.
            $table->string('active_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'admitted_at']);
        });

        Schema::create('hospital_diet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_stay_id')->constrained('hospital_stays')->restrictOnDelete();

            // « Jour » et « Heure » de la feuille papier.
            $table->date('served_on');
            $table->string('served_time', 5);

            // Les quatre colonnes « Régime » de la feuille, en texte libre.
            $table->string('tea_bread', 255)->nullable();
            $table->string('sosoa_brochette', 255)->nullable();
            $table->string('yogurt', 255)->nullable();
            $table->string('puree', 255)->nullable();
            $table->text('observation')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_stay_id', 'served_on']);
        });

        $permissions = [
            ['name' => 'hospitalization.view', 'label' => 'Consulter les patients hospitalisés et leur fiche de régime'],
            ['name' => 'hospitalization.update', 'label' => 'Renseigner la chambre / le lit d’un séjour'],
            ['name' => 'hospital_diet.record', 'label' => 'Saisir la fiche de régime d’un patient hospitalisé'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // Médecine et Soins remplissent la fiche (décision du propriétaire).
        // Par migration : un site en production ne rejoue plus
        // `RolePermissionSeeder` (ADR-064).
        foreach (['MEDICINE', 'NURSE'] as $code) {
            $role = DB::table('roles')->where('code', $code)->value('id');

            if ($role === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $role, 'permission_id' => $id],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_diet_entries');
        Schema::dropIfExists('hospital_stays');

        $ids = DB::table('permissions')
            ->whereIn('name', ['hospitalization.view', 'hospitalization.update', 'hospital_diet.record'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
