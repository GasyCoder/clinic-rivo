<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_requests', function (Blueprint $table) {
            // A Reception order has no medical consultation. The Episode,
            // Laboratory orientation and requesting user remain mandatory.
            $table->foreignId('consultation_id')->nullable()->change();
            $table->foreignId('source_orientation_id')->nullable()->change();
        });

        DB::table('catalog_items')
            ->where('type', 'SERVICE')
            ->where('billable', true)
            ->whereNull('deleted_at')
            ->where('module', 'LABORATORY')
            ->whereNull('reception_routing_mode')
            ->update([
                'reception_selectable' => true,
                'reception_routing_mode' => 'LABORATORY_DIRECT',
                'updated_at' => now(),
            ]);

        DB::table('catalog_items')
            ->where('type', 'SERVICE')
            ->where('billable', true)
            ->whereNull('deleted_at')
            ->where('module', 'MATERNITY')
            ->whereNull('reception_routing_mode')
            ->whereNotIn('code', ['MAT-CESAREAN-SIMPLE', 'MAT-CESAREAN-TWIN'])
            ->update([
                'reception_selectable' => true,
                'reception_routing_mode' => 'MATERNITY_DIRECT',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('catalog_items')
            ->whereIn('reception_routing_mode', ['LABORATORY_DIRECT', 'MATERNITY_DIRECT'])
            ->update([
                'reception_selectable' => false,
                'reception_routing_mode' => null,
                'updated_at' => now(),
            ]);

        // Keep both links nullable: a direct Reception request may already
        // exist and must remain clinically traceable after a rollback.
    }
};
