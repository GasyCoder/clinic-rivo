<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->nullable()->index();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['code']);
        });

        Schema::table('diagnoses', function (Blueprint $table): void {
            $table->foreignId('diagnostic_catalog_id')
                ->nullable()
                ->after('consultation_id')
                ->constrained('diagnostic_catalogs')
                ->restrictOnDelete();
            $table->string('catalog_code_snapshot', 50)->nullable()->after('description');
            $table->string('catalog_name_snapshot')->nullable()->after('catalog_code_snapshot');
            $table->string('manual_code', 50)->nullable()->after('catalog_name_snapshot');
            $table->text('notes')->nullable()->after('manual_code');
            $table->boolean('is_manual')->default(true)->after('notes');
        });

        $now = now();
        $permissions = [
            'diagnostic_catalog.view' => 'Voir le référentiel central des diagnostics',
            'diagnostic_catalog.manage' => 'Créer, modifier, activer et désactiver les diagnostics du référentiel',
        ];

        DB::table('permissions')->upsert(
            collect($permissions)->map(fn (string $label, string $name): array => [
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $administrationRoleId = DB::table('roles')->where('code', 'ADMINISTRATION')->value('id');
        if ($administrationRoleId) {
            $permissionIds = DB::table('permissions')->whereIn('name', array_keys($permissions))->pluck('id');
            DB::table('role_permissions')->insertOrIgnore($permissionIds->map(fn (int $permissionId): array => [
                'role_id' => $administrationRoleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'diagnostic_catalog.view',
            'diagnostic_catalog.manage',
        ])->delete();
        Cache::forget(Permission::CACHE_KEY);

        Schema::table('diagnoses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('diagnostic_catalog_id');
            $table->dropColumn([
                'catalog_code_snapshot',
                'catalog_name_snapshot',
                'manual_code',
                'notes',
                'is_manual',
            ]);
        });

        Schema::dropIfExists('diagnostic_catalogs');
    }
};
