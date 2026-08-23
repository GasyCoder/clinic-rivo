<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['role_id', 'active']);
        });

        Schema::create('professional_profile_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['professional_profile_id', 'permission_id'],
                'professional_profile_permission_unique',
            );
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('professional_profile_id')
                ->nullable()
                ->after('role_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('professional_profile_id');
        });

        Schema::dropIfExists('professional_profile_permissions');
        Schema::dropIfExists('professional_profiles');
    }
};
