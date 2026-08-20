<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16 lists only surgery.view/create/update/schedule for
     * this root resource — no delete/restore/force_delete/cancel at all
     * (unlike consultations, which get delete/restore, or prescriptions,
     * which get cancel). §11's generic rule ("acte chirurgical validé" must
     * refuse force_delete) has no permission to hang off here — flagged as
     * a CDC/§11 conflict rather than silently inventing a
     * surgery.delete/surgery.cancel permission. No SoftDeletable, no
     * cancel(); operating_room/preparation_notes back surgery.preparation.
     * update, preoperative_* fields back surgery.preoperative.view/validate
     * (no dedicated preoperative.create/update permission exists — recording
     * the assessment itself goes through the generic surgery.update).
     */
    public function up(): void
    {
        Schema::create('surgical_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('surgeon_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $table->string('procedure_name');
            $table->text('notes')->nullable();

            $table->string('operating_room')->nullable();
            $table->text('preparation_notes')->nullable();

            $table->timestamp('scheduled_at')->nullable();

            $table->text('preoperative_notes')->nullable();
            $table->foreignId('preoperative_assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preoperative_assessed_at')->nullable();
            $table->foreignId('preoperative_validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preoperative_validated_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discharged_at')->nullable();
            $table->text('discharge_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('episode_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_requests');
    }
};
