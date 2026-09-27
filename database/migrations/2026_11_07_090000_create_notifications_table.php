<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-197 — les notifications d'un compte, sur le déploiement où il se connecte
 * (le portail pour le Super Admin, le site pour son personnel). La table des
 * notifications de Laravel, plus `archived_at` : une notification archivée quitte
 * la cloche et la liste courante, sans être effacée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'archived_at', 'read_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
