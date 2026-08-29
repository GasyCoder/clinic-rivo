<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Free-text detail (type, traitement…) shown only when known_diabetes is Oui. */
    public function up(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->string('diabetes_note', 1000)->nullable()->after('known_diabetes');
        });
    }

    public function down(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn('diabetes_note');
        });
    }
};
