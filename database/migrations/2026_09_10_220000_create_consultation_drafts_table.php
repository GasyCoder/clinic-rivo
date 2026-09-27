<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typing in progress across the Médecine consultation wizard, so a reload
 * never loses it — the same protection the nursing worksheet already has
 * (ADR-073), applied to the consultation's many forms.
 *
 * Scoped to the author as well as the visit: on a shared workstation a
 * doctor must never inherit — and then save under their own name — what a
 * colleague typed but never validated.
 *
 * The payload is a map of form sections (consultation, diagnosis,
 * prescription…), each disposable and never clinical truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_drafts', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a draft carries no history worth protecting.
            $table->foreignId('episode_orientation_id')
                ->constrained('episode_orientations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['episode_orientation_id', 'created_by'], 'cd_orientation_author_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_drafts');
    }
};
