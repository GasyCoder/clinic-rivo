<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-170 — la checklist de sécurité du bloc : SIGN IN, TIME OUT, SIGN OUT.
 *
 * Une ligne par temps et par dossier (contrainte d'unicité) porte les items
 * cochés ; une table de confirmations porte **qui** a confirmé, pour quel rôle
 * et quand. Un simple `time_out = true` n'aurait rien tracé : la valeur d'un
 * TIME OUT tient à ce que le chirurgien, l'anesthésiste et l'équipe de salle
 * aient chacun confirmé leur part, nominativement.
 *
 * Les items eux-mêmes ne sont pas des colonnes : leur liste appartient à la
 * clinique (`SurgicalSafetyChecklistItems`) et la figer en schéma imposerait
 * une migration à chaque relecture de protocole. Ce qui est garanti en base,
 * c'est la trace — jamais le contenu médical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_safety_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->string('phase', 20);
            $table->json('checked_items')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['surgical_request_id', 'phase']);
        });

        Schema::create('surgical_safety_checklist_confirmations', function (Blueprint $table) {
            $table->id();
            // Contrainte nommée à la main : le nom que Laravel en déduirait
            // (`…_confirmations_surgical_safety_checklist_id_foreign`) fait
            // 76 caractères et MySQL en refuse plus de 64. SQLite l'accepte
            // sans broncher : les tests n'auraient rien vu.
            $table->foreignId('surgical_safety_checklist_id');
            $table->foreign('surgical_safety_checklist_id', 'checklist_confirmations_checklist_fk')
                ->references('id')->on('surgical_safety_checklists')->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            // Un rôle ne confirme qu'une fois : un double clic ne fabrique pas
            // deux signatures, et la base le garantit — pas seulement le code.
            $table->unique(['surgical_safety_checklist_id', 'role'], 'checklist_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_safety_checklist_confirmations');
        Schema::dropIfExists('surgical_safety_checklists');
    }
};
