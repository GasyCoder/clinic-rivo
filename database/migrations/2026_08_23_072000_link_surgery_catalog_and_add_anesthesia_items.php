<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->after('episode_id')
                ->constrained('catalog_items')
                ->restrictOnDelete();
            $table->string('procedure_details', 1000)->nullable()->after('procedure_name');
        });

        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->json('anesthetic_items')->nullable()->after('paraclinical_data');
        });
    }

    public function down(): void
    {
        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->dropColumn('anesthetic_items');
        });

        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
            $table->dropColumn(['catalog_item_id', 'procedure_details']);
        });
    }
};
