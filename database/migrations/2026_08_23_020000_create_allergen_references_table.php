<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergen_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::table('patient_allergies', function (Blueprint $table) {
            $table->foreignId('allergen_reference_id')
                ->nullable()
                ->after('patient_id')
                ->constrained('allergen_references')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_allergies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergen_reference_id');
        });

        Schema::dropIfExists('allergen_references');
    }
};
