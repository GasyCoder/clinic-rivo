<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-130 — corriger un compte rendu d'imagerie déjà enregistré.
 *
 * Un compte rendu signé est un document médical : on ne l'écrase pas en
 * silence (ADR-010). Chaque correction conserve la version qu'elle remplace,
 * avec son auteur, sa date et — facultatif — le motif, dans une table qui ne
 * se réécrit jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imaging_request_items', function (Blueprint $table): void {
            // L'état courant : la dernière correction. `resulted_at/by`
            // gardent la signature d'origine — c'est la date du compte rendu.
            $table->dateTime('corrected_at')->nullable()->after('resulted_by');
            $table->foreignId('corrected_by')->nullable()->after('corrected_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('imaging_result_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('imaging_request_item_id')->constrained()->restrictOnDelete();

            // 1 = la première version remplacée, 2 = la suivante…
            $table->unsignedSmallInteger('revision');

            // La version telle qu'elle était avant d'être remplacée.
            $table->longText('result_value');
            $table->longText('result_notes')->nullable();
            $table->dateTime('resulted_at')->nullable();
            $table->foreignId('resulted_by')->nullable()->constrained('users')->nullOnDelete();

            // Qui l'a remplacée, quand, et pourquoi (facultatif).
            $table->dateTime('superseded_at');
            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['imaging_request_item_id', 'revision']);
        });

        // Corriger un document signé n'est pas la même autorité que l'écrire
        // (même séparation qu'ADR-096 pour la réouverture d'une consultation).
        $permission = ['name' => 'imaging_results.update', 'label' => 'Corriger un compte rendu d’imagerie déjà enregistré'];

        DB::table('permissions')->updateOrInsert(
            ['name' => $permission['name']],
            $permission + ['created_at' => now(), 'updated_at' => now()],
        );

        // Par migration et non seulement dans le seeder : un site en
        // production ne rejoue plus `RolePermissionSeeder` (ADR-064).
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
        Schema::dropIfExists('imaging_result_revisions');

        Schema::table('imaging_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn('corrected_at');
        });

        $id = DB::table('permissions')->where('name', 'imaging_results.update')->value('id');

        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
