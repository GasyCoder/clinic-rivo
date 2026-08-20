<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('reversal_payment_id')
                ->nullable()
                ->unique()
                ->after('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropUnique(['reversal_payment_id']);
            $table->dropConstrainedForeignId('reversal_payment_id');
        });
    }
};
