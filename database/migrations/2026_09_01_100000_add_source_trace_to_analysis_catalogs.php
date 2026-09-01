<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->string('source_system', 60)->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('source_metadata')->nullable();
            $table->unique(['source_system', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropUnique(['source_system', 'source_id']);
            $table->dropColumn(['source_system', 'source_id', 'source_metadata']);
        });
    }
};
