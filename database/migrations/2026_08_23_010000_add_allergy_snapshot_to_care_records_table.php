<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->json('allergy_snapshot')->nullable()->after('allergy_note');
        });
    }

    public function down(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn('allergy_snapshot');
        });
    }
};
