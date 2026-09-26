<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-199 — un document généré reste figé : le « modifier » produit une nouvelle
 * version (`replaces_document_id`) et archive l'ancienne, gardée et consultable ;
 * le « supprimer » l'archive avec un motif, restaurable. Jamais d'effacement.
 *
 * Deux droits dédiés, accordés au rôle ADMINISTRATION (RH). Un site en production
 * ne rejoue plus `RolePermissionSeeder` (ADR-064), d'où leur enregistrement ici ;
 * le Super Admin du portail les reçoit à la migration (ADR-186).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'generated_documents.archive' => 'Archiver un document généré (ou le remplacer par une nouvelle version)',
        'generated_documents.restore' => 'Restaurer un document généré archivé',
    ];

    /** @var list<string> */
    private const ROLES = ['ADMINISTRATION'];

    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->foreignId('replaces_document_id')->nullable()->after('leave_request_id')
                ->constrained('generated_documents')->restrictOnDelete();
            $table->uuid('external_deleted_by_uuid')->nullable()->after('delete_reason');
            $table->string('external_deleted_by_name', 150)->nullable()->after('external_deleted_by_uuid');
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('roles')->whereIn('code', self::ROLES)->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_document_id');
            $table->dropColumn(['external_deleted_by_uuid', 'external_deleted_by_name']);
        });
    }
};
