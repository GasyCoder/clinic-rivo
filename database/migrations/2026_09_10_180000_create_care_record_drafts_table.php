<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typing in progress on a nursing worksheet, so a page reload never loses
 * it. Scoped to the author as well as the visit: on a shared workstation,
 * one nurse must never inherit — and then save under their own name —
 * values another nurse typed but never validated (ADR-032 attributes every
 * act to the person who performed it).
 *
 * Disposable by design: deleted as soon as the real record is saved, or when
 * the nurse discards the entry. It is never clinical truth and is never
 * read by any module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_record_drafts', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete, unlike the historical tables around it: a
            // draft carries no history worth protecting.
            $table->foreignId('episode_orientation_id')
                ->constrained('episode_orientations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['episode_orientation_id', 'created_by'], 'crd_orientation_author_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_record_drafts');
    }
};
