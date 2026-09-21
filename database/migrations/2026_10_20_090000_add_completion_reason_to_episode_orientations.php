<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-166 — pourquoi une orientation s'est terminée autrement que prévu.
 *
 * Un patient attendu en Médecine peut être terminé aux Soins : le motif dit
 * pourquoi la consultation prévue n'a pas eu lieu. Nul pour toute orientation
 * qui a suivi son parcours — une absence ne se remplit jamais d'office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_orientations', function (Blueprint $table): void {
            $table->text('completion_reason')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('episode_orientations', function (Blueprint $table): void {
            $table->dropColumn('completion_reason');
        });
    }
};
