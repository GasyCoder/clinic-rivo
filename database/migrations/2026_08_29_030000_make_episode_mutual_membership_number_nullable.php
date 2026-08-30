<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception feedback: the mutual membership number isn't always known
     * at intake — no longer required to record the coverage for this Episode.
     */
    public function up(): void
    {
        Schema::table('episode_mutual_coverages', function (Blueprint $table) {
            $table->string('membership_number', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('episode_mutual_coverages', function (Blueprint $table) {
            $table->string('membership_number', 100)->nullable(false)->change();
        });
    }
};
