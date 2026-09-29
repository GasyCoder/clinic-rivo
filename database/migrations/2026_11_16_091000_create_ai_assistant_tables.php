<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-222 — l'assistant d'utilisation du logiciel.
 *
 *  - `ai_assistant_settings` : une ligne par base (chaque site, et le portail), réglée
 *    depuis le portail par l'API du site. La clé du fournisseur y est chiffrée
 *    (APP_KEY) et ne quitte jamais le serveur. Une table à part d'`app_settings` : tout
 *    le formulaire commun des paramètres part au navigateur, et la réinitialisation
 *    globale (ADR-210) supprime sa ligne — elle ne doit pas effacer une clé payée.
 *  - `ai_assistant_usages` : une ligne par question posée, réussie ou non, avec les
 *    tokens que le fournisseur a comptés. Elle porte les quotas et le tableau de
 *    consommation ; elle ne garde ni la question ni la réponse (elles vivent dans les
 *    conversations du SDK).
 *
 * Trois droits : `ai_assistant.use` (utiliser l'assistant), accordé aux rôles des
 * sites comme la messagerie ; `ai_settings.view` et `ai_settings.update`, accordés à
 * aucun rôle d'un site — le Super Administrateur du portail les reçoit par la
 * synchronisation de l'ADR-186, qui suit chaque `migrate`.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'ai_assistant.use' => 'Utiliser l’assistant IA d’aide au logiciel',
        'ai_settings.view' => 'Voir les réglages de l’assistant IA (fournisseur, modèle, consommation)',
        'ai_settings.update' => 'Régler l’assistant IA : fournisseur, modèle, clé, limites',
    ];

    /** Les mêmes rôles que la messagerie (ADR-195) : SUPPORT et MAINTENANCE n'ont aucun droit par défaut (ADR-033). */
    private const USE_ROLES = ['ADMINISTRATION', 'LOGISTICS', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'];

    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 40)->nullable();
            $table->string('model', 150)->nullable();
            // Chiffrée par le cast `encrypted` du modèle : jamais en clair, jamais sérialisée.
            $table->text('api_key')->nullable();
            $table->timestamp('api_key_updated_at')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_hour')->nullable();
            $table->unsignedSmallInteger('daily_limit_per_user')->nullable();
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_assistant_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->string('provider', 40);
            $table->string('model', 150);
            // COMPLETED, FAILED ou STOPPED ; la catégorie d'erreur, jamais son message.
            $table->string('status', 20);
            $table->string('error', 40)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Un site : le socle de départ. Le portail : le Super Administrateur reçoit
        // tout par la synchronisation de l'ADR-186.
        if (config('rivo.site.type') !== 'admin') {
            $useId = DB::table('permissions')->where('name', 'ai_assistant.use')->value('id');

            foreach (DB::table('roles')->whereIn('code', self::USE_ROLES)->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $useId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('ai_assistant_usages');
        Schema::dropIfExists('ai_assistant_settings');

        Cache::forget(Permission::CACHE_KEY);
    }
};
