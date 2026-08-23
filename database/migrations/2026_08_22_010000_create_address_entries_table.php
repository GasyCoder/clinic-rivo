<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('label');
            $table->string('normalized_label')->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesWithReason();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_entries');
    }
};
