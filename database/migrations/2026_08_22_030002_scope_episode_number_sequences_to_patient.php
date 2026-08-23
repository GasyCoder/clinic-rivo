<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The former episode sequence was global to the site. New passage numbers
     * are ordinal per patient, so each patient owns an independently locked
     * counter. The nullable legacy row is kept harmlessly for rollback safety.
     */
    public function up(): void
    {
        Schema::table('episode_number_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('patient_id')->nullable()->after('id');
            $table->unique('patient_id');
        });
    }

    public function down(): void
    {
        // Collapse per-patient counters into a conservative legacy counter so
        // rolling back never moves the old global sequence backwards.
        $nextNumber = DB::table('episode_number_sequences')->max('next_number') ?? 1;
        $legacyId = DB::table('episode_number_sequences')
            ->whereNull('patient_id')
            ->value('id');

        if ($legacyId) {
            DB::table('episode_number_sequences')
                ->where('id', $legacyId)
                ->update(['next_number' => $nextNumber]);
            DB::table('episode_number_sequences')
                ->where('id', '!=', $legacyId)
                ->delete();
        } else {
            DB::table('episode_number_sequences')->delete();
            DB::table('episode_number_sequences')->insert(['next_number' => $nextNumber]);
        }

        Schema::table('episode_number_sequences', function (Blueprint $table) {
            $table->dropUnique(['patient_id']);
            $table->dropColumn('patient_id');
        });
    }
};
