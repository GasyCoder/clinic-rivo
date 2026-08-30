<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            // Nullable on purpose: a site with no configured register keeps
            // today's behaviour untouched, and historical sessions predating
            // this column can never have one attributed after the fact.
            $table->foreignId('cash_register_id')->nullable()->after('active_key')
                ->constrained('cash_registers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_id');
        });
    }
};
