<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC §9 names the four "équipe bloc" functions explicitly (chirurgien,
     * anesthésiste, infirmier de bloc, paramédical) — hence a structured
     * table rather than free text. No dedicated CDC permission exists for
     * team assignment; the corresponding Action is gated behind the generic
     * surgery.update, same as preoperative note recording.
     */
    public function up(): void
    {
        Schema::create('surgical_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('function');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['surgical_request_id', 'user_id', 'function']);
            $table->index('surgical_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_team_members');
    }
};
