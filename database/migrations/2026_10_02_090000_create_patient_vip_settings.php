<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients normaux / patients VIP, et export Excel du répertoire (ADR-133).
 *
 * Le statut VIP n'est jamais saisi : il se **calcule** à partir de deux seuils
 * propres à chaque site, réglés depuis le portail Super Administration par
 * l'API du site. Cette table n'en porte que les seuils — une seule ligne.
 * Sans ligne, le site n'a pas de VIP : aucun seuil n'est inventé.
 */
return new class extends Migration
{
    private const PORTAL_PERMISSIONS = [
        'patient_vip.view' => 'Voir les seuils des patients VIP',
        'patient_vip.update' => 'Régler les seuils des patients VIP',
    ];

    private const EXPORT = ['patients.export', 'Exporter la liste des patients en Excel'];

    public function up(): void
    {
        Schema::create('patient_vip_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            // Passages non annulés dans la fenêtre.
            $table->unsignedInteger('min_episodes');
            // Somme des encaissements réels (paiements COMPLETED) dans la fenêtre.
            $table->decimal('min_amount', 15, 2);
            $table->unsignedSmallInteger('window_months');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Le Super Administrateur central n'a pas de compte local (ADR-027).
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name', 150)->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach ([...self::PORTAL_PERMISSIONS, self::EXPORT[0] => self::EXPORT[1]] as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        $permissionId = fn (string $name) => DB::table('permissions')->where('name', $name)->value('id');

        // Réglage central : SUPER_ADMIN du portail seulement (ADR-027).
        $superAdmin = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($superAdmin && config('rivo.site.type') === 'admin') {
            foreach (array_keys(self::PORTAL_PERMISSIONS) as $name) {
                DB::table('role_permissions')->insertOrIgnore([[
                    'role_id' => $superAdmin, 'permission_id' => $permissionId($name), 'created_at' => $now, 'updated_at' => $now,
                ]]);
            }
        }

        // Une liste de patients est une donnée personnelle : l'export est
        // réservé à ADMINISTRATION par défaut, et se délègue depuis le portail
        // (ADR-064) ; la Réception ne l'a pas d'office.
        $administration = DB::table('roles')->where('code', 'ADMINISTRATION')->value('id');

        if ($administration) {
            DB::table('role_permissions')->insertOrIgnore([[
                'role_id' => $administration, 'permission_id' => $permissionId(self::EXPORT[0]), 'created_at' => $now, 'updated_at' => $now,
            ]]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_vip_settings');

        $ids = DB::table('permissions')
            ->whereIn('name', [...array_keys(self::PORTAL_PERMISSIONS), self::EXPORT[0]])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);
    }
};
