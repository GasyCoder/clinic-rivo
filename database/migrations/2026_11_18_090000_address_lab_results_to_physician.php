<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-216 — le technicien envoie les résultats au médecin ; il n'y a plus de
 * biologiste distinct.
 *
 * La demande garde à qui ses résultats sont adressés : un médecin, ou personne
 * (patient externe, choisi explicitement). `results_addressed_at` vide veut dire
 * « jamais envoyée » — une demande d'avant cette décision n'est adressée à
 * personne, et se lit donc comme avant, sans confirmation.
 *
 * Chaque analyse garde quand elle a été envoyée (`sent_at`, jamais effacé par une
 * reprise) : c'est ce que lisent les écrans du prescripteur. Une analyse déjà
 * validée l'a été au moment de son envoi ; une analyse seulement « terminée »
 * avant l'ADR-216 n'a été envoyée à personne et attend son envoi.
 *
 * `laboratory_results.validate` garde son nom (ADR-101 : le code l'écrit en
 * clair) mais change de sens : envoyer au médecin, c'est valider.
 */
return new class extends Migration
{
    private const LABEL = 'Envoyer un résultat d’analyse au médecin (l’envoi le rend définitif)';

    private const FORMER_LABEL = 'Valider ou renvoyer un résultat d’analyse (biologiste)';

    public function up(): void
    {
        Schema::table('lab_requests', function (Blueprint $table) {
            $table->foreignId('results_recipient_id')->nullable()->after('conclusion_by');
            $table->timestamp('results_addressed_at')->nullable()->after('results_recipient_id');
            $table->foreignId('results_addressed_by')->nullable()->after('results_addressed_at');

            // Nommées à la main : MySQL refuse un identifiant de plus de 64 caractères.
            $table->foreign('results_recipient_id', 'lab_requests_results_recipient_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('results_addressed_by', 'lab_requests_results_addressed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index('results_recipient_id', 'lab_requests_results_recipient_idx');
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('validated_by');
        });

        DB::table('lab_request_items')->where('status', 'VALIDATED')->whereNotNull('validated_at')
            ->update(['sent_at' => DB::raw('validated_at')]);

        DB::table('permissions')->where('name', 'laboratory_results.validate')->update([
            'label' => self::LABEL,
            'updated_at' => now(),
        ]);

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });

        Schema::table('lab_requests', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['results_recipient_id']);
                $table->dropForeign(['results_addressed_by']);
            } else {
                $table->dropForeign('lab_requests_results_recipient_fk');
                $table->dropForeign('lab_requests_results_addressed_by_fk');
            }
            $table->dropIndex('lab_requests_results_recipient_idx');
            $table->dropColumn(['results_recipient_id', 'results_addressed_at', 'results_addressed_by']);
        });

        DB::table('permissions')->where('name', 'laboratory_results.validate')->update([
            'label' => self::FORMER_LABEL,
            'updated_at' => now(),
        ]);

        Cache::forget(Permission::CACHE_KEY);
    }
};
