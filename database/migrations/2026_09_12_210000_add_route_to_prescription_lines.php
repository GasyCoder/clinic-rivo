<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The route of administration, absent until now.
 *
 * Nullable and never backfilled: lines recorded before this column existed
 * did not state a route, and guessing "oral" for them would invent a
 * clinical instruction nobody gave. They keep reading as unspecified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->string('route', 20)->nullable()->after('dosage');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->dropColumn('route');
        });
    }
};
