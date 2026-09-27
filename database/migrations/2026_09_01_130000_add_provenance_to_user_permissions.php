<?php

use App\Enums\UserPermissionSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            // The default is deliberately MANUAL: existing rows predate
            // provenance and must never be removed based on an assumption.
            $table->enum('source', array_column(UserPermissionSource::cases(), 'value'))
                ->default(UserPermissionSource::Manual->value)
                ->after('effect');
            $table->foreignId('source_profile_id')
                ->nullable()
                ->after('source')
                ->constrained('professional_profiles')
                ->restrictOnDelete();

            $table->index(['user_id', 'source', 'source_profile_id'], 'user_permissions_provenance_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropIndex('user_permissions_provenance_index');
            $table->dropConstrainedForeignId('source_profile_id');
            $table->dropColumn('source');
        });
    }
};
