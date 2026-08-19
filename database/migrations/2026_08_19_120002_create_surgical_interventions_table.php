<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16: surgery.intervention.create/update — a distinct
     * sub-resource (create AND update), unlike the fields folded directly
     * onto surgical_requests. One row per request (unique), matching a
     * single-intervention-per-case model — the CDC gives no schema for
     * re-operations/staged interventions, so that is not invented here.
     */
    public function up(): void
    {
        Schema::create('surgical_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->unique()->constrained('surgical_requests')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('procedure_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_interventions');
    }
};
