<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('request_hash', 64);
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_records');
    }
};
