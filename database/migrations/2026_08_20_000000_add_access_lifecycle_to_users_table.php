<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('role_id')->index();
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->foreignId('deactivated_by')->nullable()->after('remember_token')->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable()->after('deactivated_by');
            $table->text('deactivation_reason')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['active', 'last_login_at', 'deactivated_at', 'deactivation_reason']);
        });
    }
};
