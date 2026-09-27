<?php

use App\Models\AnalysisCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->string('exam_category', 100)->nullable()->after('description');
            $table->boolean('is_bold')->default(false)->after('is_active');
        });

        // Backfill from what the historical import already captured as
        // read-only metadata (ADR-063) — exam_category becomes a real, first
        // -class field instead of only living inside source_metadata.
        AnalysisCatalog::withTrashed()
            ->whereNotNull('source_metadata')
            ->chunkById(200, function ($analyses) {
                foreach ($analyses as $analysis) {
                    $metadata = $analysis->source_metadata ?? [];
                    $examName = trim((string) ($metadata['exam_name'] ?? ''));

                    $analysis->newQuery()->whereKey($analysis->getKey())->update(array_filter([
                        'exam_category' => $examName !== '' ? $examName : null,
                        'is_bold' => (bool) ($metadata['legacy_is_bold'] ?? false),
                    ], fn ($value) => $value !== null));
                }
            });
    }

    public function down(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropColumn(['exam_category', 'is_bold']);
        });
    }
};
