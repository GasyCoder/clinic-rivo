<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('external_actor_uuid')->nullable()->after('user_id')->index();
            $table->string('external_actor_name', 150)->nullable()->after('external_actor_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['external_actor_uuid', 'external_actor_name']);
        });
    }
};
