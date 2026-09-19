<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le médecin peut retirer un acte demandé aux Soins tant que Soins n'a pas
 * pris le patient en charge. Retirer n'est pas supprimer (ADR-010) : la
 * ligne reste, avec son auteur, sa date et son motif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_order_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('not_performed_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('care_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
