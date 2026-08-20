<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client CDCF: "antécédents médicaux" is part of the permanent
     * "dossier patient" and explicitly listed in CDC §19's inter-site
     * transfer payload — hence an append-only, historized, UUID-bearing
     * list rather than a single overwritable text field: each entry stays
     * individually auditable and a correction never erases what was
     * previously known.
     */
    public function up(): void
    {
        Schema::create('patient_antecedents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->text('description');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_antecedents');
    }
};
