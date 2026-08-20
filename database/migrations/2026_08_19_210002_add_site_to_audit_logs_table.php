<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('site_code', 20)->nullable()->after('module')->index();
            $table->string('site_name')->nullable()->after('site_code');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['site_code']);
            $table->dropColumn(['site_code', 'site_name']);
        });
    }
};
