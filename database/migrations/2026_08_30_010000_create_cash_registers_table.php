<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesWithReason();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
