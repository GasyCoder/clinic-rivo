<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-111 — protocoles thérapeutiques de la clinique.
 *
 * Le système propose un diagnostic et une ordonnance, mais ne les invente
 * pas : ils viennent de protocoles écrits par les médecins de la clinique.
 * Rien dans le référentiel ne dit quel médicament traite quoi, ni à quelle
 * dose selon l'âge ou le poids — c'est précisément ce que ces tables portent,
 * et c'est une décision médicale, donc elle est saisie par des médecins.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'clinical_protocols.view', 'label' => 'Consulter les protocoles thérapeutiques'],
        ['name' => 'clinical_protocols.manage', 'label' => 'Rédiger et archiver les protocoles thérapeutiques'],
    ];

    public function up(): void
    {
        Schema::create('clinical_protocols', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Le diagnostic que le protocole traite : c'est lui qui relie un
            // diagnostic posé à une ordonnance proposée.
            $table->foreignId('diagnostic_catalog_id')->constrained('diagnostic_catalogs')->restrictOnDelete();
            $table->string('name');

            // Signes évocateurs, un par entrée : ce que le moteur cherche dans
            // l'interrogatoire et l'examen pour proposer ce diagnostic.
            $table->json('indications')->nullable();

            // Population visée. Une borne absente ne restreint rien ; une
            // borne posée exclut un patient dont la valeur est inconnue — un
            // protocole pédiatrique ne s'applique pas « faute de mieux ».
            $table->unsignedSmallInteger('min_age_years')->nullable();
            $table->unsignedSmallInteger('max_age_years')->nullable();
            $table->string('sex', 1)->nullable();
            $table->decimal('min_weight_kg', 5, 2)->nullable();
            $table->decimal('max_weight_kg', 5, 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('clinical_protocol_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('clinical_protocol_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('dosage')->nullable();
            $table->string('route', 30)->nullable();
            $table->string('frequency');
            $table->string('duration')->nullable();
            // Absente : la quantité se déduit de la posologie au moment de
            // la prescription (ADR-110), plutôt que d'être figée ici.
            $table->unsignedInteger('quantity')->nullable();
            $table->string('instructions', 1000)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Traçabilité : ce qui a été proposé, et par quel protocole. Le
        // médecin reste l'auteur de la ligne — la trace dit seulement d'où
        // venait la proposition qu'il a retenue.
        foreach (['diagnoses', 'prescription_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('suggestion_source', 20)->nullable();
                $table->foreignId('clinical_protocol_id')->nullable()->constrained('clinical_protocols')->restrictOnDelete();
            });
        }

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // Un protocole est une décision médicale : c'est le rôle Médecine
        // qui le rédige. Enregistré par migration, un site en production ne
        // rejouant plus `RolePermissionSeeder` (ADR-064).
        $medicine = DB::table('roles')->where('code', 'MEDICINE')->value('id');

        if ($medicine === null) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

            if ($id !== null) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $medicine, 'permission_id' => $id],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (['diagnoses', 'prescription_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('clinical_protocol_id');
                $table->dropColumn('suggestion_source');
            });
        }

        Schema::dropIfExists('clinical_protocol_lines');
        Schema::dropIfExists('clinical_protocols');

        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
