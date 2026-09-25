<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les paramètres de l'application, propres à chaque déploiement (ADR-184).
 *
 * Une seule ligne par base : chaque site — et le portail pour lui-même — a son
 * nom, sa devise, son logo, son icône, sa couleur, sa visibilité pour les
 * moteurs de recherche, sa façon d'écrire l'Ariary, ses tranches d'âge des
 * patients, son identité légale et son directeur général.
 * Sans ligne, ou pour une colonne vide, l'application garde la configuration de
 * déploiement (`config/rivo.php`) : rien ne change tant que personne n'a réglé.
 *
 * Le portail règle un site uniquement par l'API de ce site (ADR-004, ADR-027).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'settings.view' => 'Voir les paramètres de l’application (nom, logo, couleurs, devise, âges, identité légale)',
        'settings.update' => 'Modifier les paramètres de l’application (nom, logo, couleurs, devise, âges, identité légale)',
    ];

    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();

            // Identité et marque.
            $table->string('app_name', 80)->nullable();
            // Devise ou slogan : la phrase de l'établissement, sur la page de connexion.
            $table->string('app_tagline', 150)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('icon_path')->nullable();

            // Moteurs de recherche : vide, la configuration du déploiement décide
            // (masquée par défaut — une application clinique n'a rien à y montrer).
            $table->boolean('search_engines_hidden')->nullable();

            // L'Ariary et sa façon de s'écrire : aucun montant n'est converti.
            $table->string('currency_label', 10)->default('Ar');
            $table->string('currency_position', 6)->default('after');
            $table->unsignedTinyInteger('currency_decimals')->default(0);

            // Tranches d'âge, en années révolues : bébé jusqu'à `baby_max_age`,
            // enfant jusqu'à `child_max_age`, adulte au-delà.
            $table->unsignedTinyInteger('baby_max_age')->default(1);
            $table->unsignedTinyInteger('child_max_age')->default(15);

            // Direction : nom, titre et signature des documents RH.
            $table->string('director_name', 150)->nullable();
            $table->string('director_title', 150)->nullable();
            $table->string('signature_path')->nullable();

            // Identité légale imprimée sur les documents.
            $table->string('legal_nif', 40)->nullable();
            $table->string('legal_stat', 40)->nullable();
            $table->string('legal_address')->nullable();
            $table->string('legal_phone', 40)->nullable();
            $table->string('legal_email', 150)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_account', 60)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Le Super Administrateur central n'a pas de compte local (ADR-027).
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name', 150)->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        // Réglage central : SUPER_ADMIN du portail seulement (ADR-027). Un site
        // ne donne ces droits à aucun rôle : le portail les transmet par l'API.
        $superAdmin = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($superAdmin && config('rivo.site.type') === 'admin') {
            foreach (array_keys(self::PERMISSIONS) as $name) {
                $permission = DB::table('permissions')->where('name', $name)->value('id');

                if ($permission) {
                    DB::table('role_permissions')->insertOrIgnore([[
                        'role_id' => $superAdmin, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
                    ]]);
                }
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
