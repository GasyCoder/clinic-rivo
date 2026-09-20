<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-144 — un nouveau-né consigné en Maternité peut devenir un patient relié à sa mère.
 *
 * Le lien vit dans sa propre table plutôt que dans `patients` : l'identité permanente reste
 * inchangée, et retirer un jour cette relation ne touche pas au dossier du patient.
 *
 * `newborn_uuid` désigne le bébé **dans** `maternity_records.newborn_data` : les fiches y sont
 * un tableau sans identifiant propre, et une position change dès qu'une fiche est retirée.
 * L'unicité (dossier Maternité, bébé) rend le geste idempotent — un double clic ne crée jamais
 * deux patients pour le même enfant, ce que la détection de doublons ne pourrait pas garantir
 * pour des jumeaux (même nom, même date de naissance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_newborn_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->unique()->constrained('patients')->restrictOnDelete();
            $table->foreignId('mother_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('maternity_record_id')->constrained('maternity_records')->restrictOnDelete();
            $table->uuid('newborn_uuid');
            $table->unsignedTinyInteger('birth_rank');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['maternity_record_id', 'newborn_uuid']);
            $table->index('mother_patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_newborn_links');
    }
};
