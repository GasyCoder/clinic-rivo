<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Créer un rôle depuis le portail, sans déploiement.
 *
 * L'ADR-064 avait déjà sorti le *socle* d'un rôle du code : un Super Admin
 * coche ses permissions depuis `admin.rivo.mg`. Le rôle lui-même restait en
 * revanche figé dans `RoleSeeder` — ajouter « Kinésithérapeute » exigeait un
 * déploiement. Ces quatre permissions ouvrent cette porte.
 *
 * Un rôle n'est jamais supprimé physiquement (ADR-009/010) : il est l'auteur
 * historique des droits de tous les comptes qui l'ont porté. L'archivage est
 * donc un Soft Delete motivé, et la restauration explicite.
 *
 * `code` reste l'identité d'un rôle d'un site à l'autre — `RolePermissionSeeder`
 * en dépend, et MEDICINE désigne le même métier partout (ADR-005 : un UUID
 * local n'aurait ici aucun sens inter-site).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'roles.create' => 'Créer un rôle',
        'roles.update' => 'Renommer un rôle',
        'roles.archive' => 'Archiver un rôle',
        'roles.restore' => 'Restaurer un rôle archivé',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->string('delete_reason')->nullable()->after('deleted_by');
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name): array => [
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Portail central uniquement (ADR-027) : un compte clinique ne gère
        // pas le référentiel des rôles, et le socle SUPER_ADMIN d'un site
        // reste volontairement vide.
        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')
                    ->whereIn('name', array_keys(self::PERMISSIONS))
                    ->pluck('id')
                    ->map(fn (int $permissionId): array => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'delete_reason']);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
