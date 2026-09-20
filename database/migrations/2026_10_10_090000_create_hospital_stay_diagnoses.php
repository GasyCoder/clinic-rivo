<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-147 — le diagnostic posé au terme d'un séjour, et la Réception qui lit le module.
 *
 * Deux constats du propriétaire, une seule migration parce qu'ils touchent le même écran.
 *
 * **Le diagnostic de sortie appartient au séjour.** Un `Diagnosis` appartient à une
 * `Consultation` (ADR-035), et celle qui a demandé l'hospitalisation est le plus souvent
 * close bien avant la sortie — l'ADR-076 refuse alors toute écriture ordinaire. Ce que le
 * médecin conclut au terme du séjour n'est pourtant pas une correction de cette
 * consultation : c'est un fait clinique du séjour. Il a donc sa propre table, append-only,
 * avec son auteur et sa date ; la consultation close n'est jamais réécrite.
 *
 * **La Réception lit le module.** `hospitalization.view` ouvre le détail, le dossier et
 * l'impression de la fiche ; les écritures gardent chacune leur droit
 * (`hospitalization.update`, `hospital_diet.record`, `medical_discharge.create`), qu'elle
 * ne reçoit pas. Un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_stay_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('hospital_stay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diagnostic_catalog_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            // Instantanés : une correction ultérieure du catalogue ne réécrit jamais un
            // diagnostic déjà posé (ADR-024).
            $table->string('catalog_code_snapshot')->nullable();
            $table->string('catalog_name_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_stay_id', 'id']);
        });

        $view = DB::table('permissions')->where('name', 'hospitalization.view')->value('id');
        $role = DB::table('roles')->where('code', 'RECEPTION')->whereNull('deleted_at')->value('id');

        if ($view !== null && $role !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role,
                'permission_id' => $view,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_stay_diagnoses');
    }
};
