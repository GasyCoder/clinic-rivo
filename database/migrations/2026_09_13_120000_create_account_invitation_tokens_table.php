<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation tokens live apart from "forgot password" tokens.
 *
 * An invitation stays valid for days, a reset link for an hour. Sharing one
 * table would let a stolen one-hour reset token be replayed through the
 * invitation page and live for days; separate tables make each token valid
 * only for the flow that issued it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitation_tokens');
    }
};
