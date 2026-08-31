<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_reference_values', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 40)->index();
            $table->string('code', 80);
            $table->string('label');
            $table->boolean('active')->default(true)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->unique(['type', 'code']);
            $table->unique(['type', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_reference_values');
    }
};
