<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_tariffs', function (Blueprint $table) {
            // Existing historical rows are the legacy site tariff and are
            // classified technically as STANDARD. Their amounts are not
            // changed or revalidated by this migration.
            $table->string('tariff_category', 20)
                ->default('STANDARD')
                ->after('catalog_item_id');
        });

        Schema::table('catalog_tariffs', function (Blueprint $table) {
            $table->dropUnique(['catalog_item_id', 'active_key']);
            $table->unique(
                ['catalog_item_id', 'tariff_category', 'active_key'],
                'catalog_tariffs_item_category_active_unique',
            );
            $table->index(
                ['catalog_item_id', 'tariff_category', 'effective_from'],
                'catalog_tariffs_item_category_effective_index',
            );
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->foreignId('catalog_tariff_id')
                ->nullable()
                ->after('catalog_item_id')
                ->constrained('catalog_tariffs')
                ->restrictOnDelete();
            $table->string('tariff_category', 20)
                ->default('STANDARD')
                ->after('catalog_tariff_id');

            // A missing category tariff must never block the clinical route.
            // In that case the request is kept with a null price and billing
            // remains pending until Administration configures the price list.
            $table->decimal('unit_price', 15, 2)->nullable()->change();
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->string('tariff_category', 20)
                ->default('STANDARD')
                ->after('catalog_tariff_id');
        });
    }

    public function down(): void
    {
        // Financial tariff history must never be silently discarded simply
        // to make a rollback possible.
        if (DB::table('catalog_tariffs')->where('tariff_category', 'MUTUAL')->exists()) {
            throw new RuntimeException(
                'Rollback refusé : des tarifs Mutuelle existent et doivent être conservés.',
            );
        }

        Schema::table('billable_items', function (Blueprint $table) {
            $table->dropColumn('tariff_category');
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->nullable(false)->change();
            $table->dropConstrainedForeignId('catalog_tariff_id');
            $table->dropColumn('tariff_category');
        });

        Schema::table('catalog_tariffs', function (Blueprint $table) {
            $table->dropIndex('catalog_tariffs_item_category_effective_index');
            $table->dropUnique('catalog_tariffs_item_category_active_unique');
            $table->dropColumn('tariff_category');
        });

        Schema::table('catalog_tariffs', function (Blueprint $table) {
            $table->unique(['catalog_item_id', 'active_key']);
        });
    }
};
