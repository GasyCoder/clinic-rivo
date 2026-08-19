<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16 lists only surgery.consumables.create — append-only,
     * same reasoning as diagnoses/complications. `label` is free text, not a
     * foreign key to a medicines/products catalog: Pharmacie (Phase 4)
     * doesn't exist yet, and per ADR-013/CLAUDE.md this module must never
     * touch Pharmacy's stock — this table only records what was used for
     * billing/traceability, it never decrements anything.
     */
    public function up(): void
    {
        Schema::create('surgical_consumables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('surgical_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_consumables');
    }
};
