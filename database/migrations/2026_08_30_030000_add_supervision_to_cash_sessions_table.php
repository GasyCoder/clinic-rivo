<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->foreignId('locked_by')->nullable()->after('closed_by')
                ->constrained('users')->restrictOnDelete();
            $table->uuid('external_locked_by_uuid')->nullable()->after('locked_by')->index();
            $table->string('external_locked_by_name', 150)->nullable()->after('external_locked_by_uuid');
            $table->timestamp('locked_at')->nullable()->after('external_locked_by_name');
            $table->text('lock_reason')->nullable()->after('locked_at');

            $table->foreignId('unlocked_by')->nullable()->after('lock_reason')
                ->constrained('users')->restrictOnDelete();
            $table->uuid('external_unlocked_by_uuid')->nullable()->after('unlocked_by')->index();
            $table->string('external_unlocked_by_name', 150)->nullable()->after('external_unlocked_by_uuid');
            $table->timestamp('unlocked_at')->nullable()->after('external_unlocked_by_name');

            // A central Super Administrator never owns a row in the local
            // users table. These columns preserve their distributed identity
            // without weakening the existing closed_by foreign key.
            $table->uuid('external_closed_by_uuid')->nullable()->after('unlocked_at')->index();
            $table->string('external_closed_by_name', 150)->nullable()->after('external_closed_by_uuid');
            $table->text('closing_reason')->nullable()->after('external_closed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropIndex(['external_locked_by_uuid']);
            $table->dropConstrainedForeignId('unlocked_by');
            $table->dropIndex(['external_unlocked_by_uuid']);
            $table->dropIndex(['external_closed_by_uuid']);
            $table->dropColumn([
                'external_locked_by_uuid', 'external_locked_by_name', 'locked_at', 'lock_reason',
                'external_unlocked_by_uuid', 'external_unlocked_by_name', 'unlocked_at',
                'external_closed_by_uuid', 'external_closed_by_name', 'closing_reason',
            ]);
        });
    }
};
