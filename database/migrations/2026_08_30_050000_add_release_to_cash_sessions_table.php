<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            // Nullable so a centrally released session can sit ownerless
            // until the next local actor claims it by using the till —
            // never by Super Admin picking who that will be (ADR-027 keeps
            // central accounts out of local user rosters).
            $table->foreignId('opened_by')->nullable()->change();

            $table->foreignId('released_by')->nullable()->after('closing_reason')
                ->constrained('users')->restrictOnDelete();
            $table->uuid('external_released_by_uuid')->nullable()->after('released_by')->index();
            $table->string('external_released_by_name', 150)->nullable()->after('external_released_by_uuid');
            $table->timestamp('released_at')->nullable()->after('external_released_by_name');
            $table->text('release_reason')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by');
            $table->dropIndex(['external_released_by_uuid']);
            $table->dropColumn([
                'external_released_by_uuid', 'external_released_by_name', 'released_at', 'release_reason',
            ]);
            $table->foreignId('opened_by')->nullable(false)->change();
        });
    }
};
