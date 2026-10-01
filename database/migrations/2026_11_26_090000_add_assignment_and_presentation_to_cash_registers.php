<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->string('color', 7)->default('#2563EB')->after('normalized_name');
            $table->decimal('opening_fund_amount', 15, 2)->nullable()->after('color');
            $table->foreignId('assigned_user_id')->nullable()->after('opening_fund_amount')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['color', 'opening_fund_amount']);
        });
    }
};
