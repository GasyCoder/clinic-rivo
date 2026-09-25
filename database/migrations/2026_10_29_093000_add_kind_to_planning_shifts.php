<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-194 — deux plannings : le service du personnel et les gardes. Un
 * créneau dit lequel il est ; tous les créneaux existants sont du service,
 * ce qu'ils étaient faute de distinction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_shifts', function (Blueprint $table): void {
            $table->string('kind', 20)->default('SHIFT')->after('department_id');
            $table->index(['kind', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('planning_shifts', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'starts_at']);
            $table->dropColumn('kind');
        });
    }
};
