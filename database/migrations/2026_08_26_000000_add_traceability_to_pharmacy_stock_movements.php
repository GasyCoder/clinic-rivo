<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
            // CDCF §34.2: every stock movement identifies an origin and a
            // destination. Nullable preserves pre-existing movements; every
            // new local entry created by this module requires both values.
            $table->string('origin', 150)->nullable()->after('source_key');
            $table->string('destination', 150)->nullable()->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
            $table->dropColumn(['origin', 'destination']);
        });
    }
};
