<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('external_deactivated_by_uuid')->nullable()->after('deactivated_by')->index();
            $table->string('external_deactivated_by_name', 150)->nullable()->after('external_deactivated_by_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['external_deactivated_by_uuid']);
            $table->dropColumn(['external_deactivated_by_uuid', 'external_deactivated_by_name']);
        });
    }
};
