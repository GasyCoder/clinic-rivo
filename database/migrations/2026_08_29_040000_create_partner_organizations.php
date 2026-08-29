<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('episode_partner_coverages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('partner_organization_id')->constrained('partner_organizations')->restrictOnDelete();
            $table->uuid('organization_uuid_snapshot');
            $table->string('organization_name_snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_partner_coverages');
        Schema::dropIfExists('partner_organizations');
    }
};
