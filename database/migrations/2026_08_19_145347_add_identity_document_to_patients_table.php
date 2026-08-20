<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réception feedback: CIN (carte d'identité nationale) or passport
     * number, for patients who have one — not in the CDC §21 schema,
     * added on explicit Réception request. Both nullable: not every
     * patient carries an ID document (minors, emergencies).
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('identity_document_type')->nullable()->after('civility');
            $table->string('identity_document_number')->nullable()->after('identity_document_type');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['identity_document_type', 'identity_document_number']);
        });
    }
};
