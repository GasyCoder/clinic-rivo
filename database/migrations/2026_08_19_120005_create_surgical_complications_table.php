<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16 lists only surgery.complications.create — no
     * update/delete — so entries are append-only here, same reasoning as
     * diagnoses: a corrected complication record is a new row, the clinical
     * trail never silently changes shape.
     */
    public function up(): void
    {
        Schema::create('surgical_complications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->text('description');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index('surgical_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_complications');
    }
};
