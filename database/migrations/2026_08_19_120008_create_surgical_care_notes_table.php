<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16 lists two structurally identical, create-only
     * permissions here — surgery.care.create (soins peropératoires) and
     * surgery.postoperative_care.create (soins postopératoires) — modeled
     * as one append-only table distinguished by `phase`, rather than two
     * duplicate tables, since neither has its own view/update/delete
     * permission to justify separate resources.
     */
    public function up(): void
    {
        Schema::create('surgical_care_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->string('phase');
            $table->text('note');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index('surgical_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_care_notes');
    }
};
