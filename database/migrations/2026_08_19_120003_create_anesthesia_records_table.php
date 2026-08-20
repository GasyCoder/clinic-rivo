<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16: anesthesia.view/create/update/validate — modeled
     * as its own sub-resource (same create/update/validate shape as
     * surgical_reports) rather than fields on surgical_requests, since it
     * has its own dedicated permission namespace. `type` (générale/
     * locorégionale/locale) is intentionally not a column: the CDC does not
     * enumerate anesthesia types, so `notes` stays free text rather than
     * inventing a clinical classification.
     */
    public function up(): void
    {
        Schema::create('anesthesia_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->unique()->constrained('surgical_requests')->restrictOnDelete();
            $table->foreignId('anesthetist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('administered_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anesthesia_records');
    }
};
