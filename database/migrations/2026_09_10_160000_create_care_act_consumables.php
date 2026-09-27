<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-072 (amendement 2026-09-10) — matériel habituellement utilisé par un
 * acte de soins. Purement une suggestion de saisie : l'infirmier confirme,
 * ajuste ou retire toujours ce qui a réellement été utilisé. Aucune
 * association n'est déduite du nom ou du code d'un acte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_act_consumables', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // The nursing act (catalog_items, SERVICE / CARE).
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->unsignedBigInteger('default_quantity')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'medicine_id'], 'cac_item_medicine_unique');
            $table->index(['catalog_item_id', 'position'], 'cac_item_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_act_consumables');
    }
};
