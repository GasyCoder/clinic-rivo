<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'analysis_catalog.view' => 'Voir le catalogue structuré des analyses',
        'analysis_catalog.create' => 'Créer une définition d’analyse',
        'analysis_catalog.update' => 'Modifier une définition d’analyse et ses références',
        'analysis_catalog.activate' => 'Activer une définition d’analyse',
        'analysis_catalog.deactivate' => 'Désactiver une définition d’analyse',
        'analysis_catalog.import' => 'Importer le catalogue des analyses',
        'analysis_catalog.export' => 'Exporter le catalogue des analyses',
    ];

    public function up(): void
    {
        Schema::create('analysis_catalogs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('analysis_catalogs')->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->string('level', 20)->default('NORMAL');
            $table->string('designation');
            $table->text('description')->nullable();
            $table->string('result_type', 20)->default('TEXT');
            $table->string('reference_general')->nullable();
            $table->string('reference_male')->nullable();
            $table->string('reference_female')->nullable();
            $table->string('reference_child_male')->nullable();
            $table->string('reference_child_female')->nullable();
            $table->string('unit', 60)->nullable();
            $table->json('predefined_values')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['catalog_item_id', 'display_order']);
            $table->index(['parent_id', 'display_order']);
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->json('reference_snapshot')->nullable()->after('result_notes');
        });

        $now = now();
        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name): array => compact('name', 'label') + [
                'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ADMINISTRATION', 'SUPER_ADMIN'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore($permissionIds->map(fn (int $permissionId) => [
                'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->dropColumn('reference_snapshot');
        });
        Schema::dropIfExists('analysis_catalogs');
        DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
        Cache::forget(Permission::CACHE_KEY);
    }
};
