<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tenders a named cash desk actually accepts. A desk with no row here
 * accepts every active tender of the site — that is the historical behaviour
 * and stays the default, exactly as a site with no named register keeps its
 * single site-wide till (ADR-058). Restricting is opt-in, per desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_payment_method', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            // A tender referenced by a desk is never deleted, like one
            // referenced by a payment.
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cash_register_id', 'payment_method_id'], 'cash_register_payment_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_payment_method');
    }
};
