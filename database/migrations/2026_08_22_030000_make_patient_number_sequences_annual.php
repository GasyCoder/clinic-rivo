<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient numbers are now scoped by calendar year: M-26-0001.
     *
     * The former sequence contained a single site-local row. Its counter is
     * deliberately attached to the migration year instead of being reset, so
     * an upgrade cannot reuse a number that may already have been allocated by
     * the legacy generator. Existing patient_number values are never rewritten.
     */
    public function up(): void
    {
        Schema::table('patient_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('id');
        });

        $legacy = DB::table('patient_number_sequences')
            ->whereNull('year')
            ->orderBy('id')
            ->first();

        if ($legacy) {
            DB::table('patient_number_sequences')
                ->where('id', $legacy->id)
                ->update(['year' => now()->year]);
        }

        Schema::table('patient_number_sequences', function (Blueprint $table) {
            $table->unique('year');
        });
    }

    public function down(): void
    {
        // The legacy generator expects one site-wide row. Collapse annual
        // counters before removing their discriminator so a rollback cannot
        // leave several competing sequence rows behind.
        $nextNumber = DB::table('patient_number_sequences')->max('next_number') ?? 1;
        $legacyId = DB::table('patient_number_sequences')
            ->orderBy('id')
            ->value('id');

        if ($legacyId) {
            DB::table('patient_number_sequences')
                ->where('id', $legacyId)
                ->update(['next_number' => $nextNumber]);
            DB::table('patient_number_sequences')
                ->where('id', '!=', $legacyId)
                ->delete();
        } else {
            DB::table('patient_number_sequences')->insert(['next_number' => $nextNumber]);
        }

        Schema::table('patient_number_sequences', function (Blueprint $table) {
            $table->dropUnique(['year']);
            $table->dropColumn('year');
        });
    }
};
