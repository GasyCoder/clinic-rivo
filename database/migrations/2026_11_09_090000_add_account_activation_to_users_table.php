<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-202 — l'employé choisit lui-même son mot de passe, à sa première connexion.
 *
 *  - `activation_open_until` : jusqu'à quand la première connexion (email, puis
 *    « nouveau mot de passe ») est ouverte pour ce compte ;
 *  - `activated_at` : quand la personne a choisi son mot de passe — ou s'est
 *    connectée pour la première fois avec un mot de passe remis avant cette règle.
 *
 * Reprise des accès créés avec un mot de passe remis au RH (ADR-197) : un compte
 * déjà utilisé est « activé » à sa première connexion ; un compte jamais utilisé
 * passe en première connexion. Les mots de passe encore gardés chiffrés pour la
 * remise sont effacés : il n'y a plus rien à remettre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('activation_open_until')->nullable()->after('last_login_at');
            $table->timestamp('activated_at')->nullable()->after('activation_open_until');
        });

        if (! Schema::hasTable('staff_access_handover_items')) {
            return;
        }

        $days = max(1, (int) config('rivo.account_activation.days', 14));
        $userIds = DB::table('staff_access_handover_items')->whereNotNull('user_id')->pluck('user_id')->unique()->values();

        foreach ($userIds->chunk(200) as $chunk) {
            DB::table('users')->whereIn('id', $chunk)->whereNull('activated_at')->whereNotNull('last_login_at')
                ->update(['activated_at' => DB::raw('last_login_at')]);
            DB::table('users')->whereIn('id', $chunk)->whereNull('activated_at')->whereNull('last_login_at')->where('active', true)
                ->update(['activation_open_until' => now()->addDays($days)]);
        }

        DB::table('staff_access_handover_items')->whereNotNull('secret')->update(['secret' => null]);
        DB::table('staff_access_handovers')->whereNull('purged_at')
            ->update(['purged_at' => now(), 'purge_reason' => 'FIRST_LOGIN']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['activation_open_until', 'activated_at']);
        });
    }
};
