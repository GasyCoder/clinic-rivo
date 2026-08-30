<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->json('consultation_data')->nullable()->after('anesthetist_id');
            $table->json('paraclinical_data')->nullable()->after('consultation_data');
            $table->foreignId('assessment_validated_by')
                ->nullable()
                ->after('administered_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('assessment_validated_at')->nullable()->after('assessment_validated_by');
        });
    }

    public function down(): void
    {
        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->dropForeign(['assessment_validated_by']);
            $table->dropColumn([
                'consultation_data',
                'paraclinical_data',
                'assessment_validated_by',
                'assessment_validated_at',
            ]);
        });
    }
};
