<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-194 — la photo d'identité 4 × 4 d'un employé ou d'un stagiaire. Le
 * fichier vit sur le disque privé, jamais sous public/ : il se lit par un
 * contrôleur qui revérifie le droit (ADR-066, comme les pièces RH).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('observation');
            $table->timestamp('photo_updated_at')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'photo_updated_at']);
        });
    }
};
