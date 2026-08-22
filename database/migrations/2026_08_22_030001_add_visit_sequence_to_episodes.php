<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * visit_sequence is the patient's own passage ordinal. It is nullable so
     * imports and historical rows can retain their original identifiers, while
     * the composite unique key protects every newly generated ordinal.
     */
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedInteger('visit_sequence')->nullable()->after('patient_id');
        });

        // Preserve every historical episode_number. Only attach a stable
        // ordinal, ordered by the actual passage date then the local ID.
        DB::table('episodes')
            ->select('patient_id')
            ->distinct()
            ->orderBy('patient_id')
            ->chunk(500, function ($patients): void {
                foreach ($patients as $patient) {
                    $episodeIds = DB::table('episodes')
                        ->where('patient_id', $patient->patient_id)
                        ->orderBy('started_at')
                        ->orderBy('id')
                        ->pluck('id');

                    foreach ($episodeIds as $index => $episodeId) {
                        DB::table('episodes')
                            ->where('id', $episodeId)
                            ->update(['visit_sequence' => $index + 1]);
                    }
                }
            });

        Schema::table('episodes', function (Blueprint $table) {
            $table->unique(['patient_id', 'visit_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropUnique(['patient_id', 'visit_sequence']);
            $table->dropColumn('visit_sequence');
        });
    }
};
