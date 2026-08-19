<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15 lists only `diagnoses.view/create/update` — no
     * delete/restore/cancel — so entries are append-only here: a new
     * hypothesis or a corrected final diagnosis is a new row, not an
     * overwrite, matching how clinical history should never silently
     * disappear (ADR-010's spirit, applied even where CDC doesn't
     * explicitly list a delete-family permission).
     */
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->string('type');
            $table->text('description');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('consultation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
