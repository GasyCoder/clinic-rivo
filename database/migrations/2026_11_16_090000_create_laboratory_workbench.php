<?php

use App\Enums\LabEntryMode;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-213 — la paillasse du Laboratoire.
 *
 * Référentiel de microbiologie (familles, bactéries, antibiotiques), repris du
 * laboratoire que la clinique utilisait déjà ; résultats structurés par analyse
 * du catalogue ; antibiogrammes ; et le parcours d'une analyse demandée :
 * saisie → terminée → validée par le biologiste, ou renvoyée à refaire.
 *
 * `lab_request_items.result_value` et `resulted_at` gardent leur sens : le
 * résultat rendu (terminé par le technicien) que la Médecine, la Maternité et
 * le séjour lisent déjà. Les lignes déjà rendues passent « À valider » : elles
 * n'ont jamais été validées, et le dire serait inventer une validation.
 *
 * Un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064), d'où
 * les droits enregistrés ici.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'laboratory_results.validate' => 'Valider ou renvoyer un résultat d’analyse (biologiste)',
        'laboratory_results.flag_critical' => 'Signaler un résultat d’analyse critique',
        'lab_microbiology.view' => 'Voir le référentiel de microbiologie (familles, bactéries, antibiotiques)',
        'lab_microbiology.create' => 'Ajouter au référentiel de microbiologie',
        'lab_microbiology.update' => 'Modifier le référentiel de microbiologie',
        'lab_microbiology.archive' => 'Archiver une entrée du référentiel de microbiologie',
        'lab_microbiology.restore' => 'Restaurer une entrée du référentiel de microbiologie',
    ];

    private const GRANTS = [
        'LABORATORY' => [
            'laboratory_results.validate', 'laboratory_results.flag_critical',
            'lab_microbiology.view', 'lab_microbiology.create', 'lab_microbiology.update',
            'lab_microbiology.archive', 'lab_microbiology.restore',
        ],
    ];

    public function up(): void
    {
        Schema::create('lab_bacterium_families', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('normalized_name', 150)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('lab_bacteria', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('lab_bacterium_families')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('normalized_name', 200);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
            $table->unique(['family_id', 'normalized_name'], 'lab_bacteria_family_name_unique');
        });

        Schema::create('lab_antibiotics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('lab_bacterium_families')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('normalized_name', 200);
            $table->text('comment')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
            $table->unique(['family_id', 'normalized_name'], 'lab_antibiotics_family_name_unique');
        });

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->string('entry_mode', 30)->nullable()->after('result_type');
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->string('status', 20)->default('PENDING')->after('catalog_item_name_snapshot')->index();
            $table->text('conclusion')->nullable()->after('result_notes');
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_reason')->nullable();
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_request_item_id')->constrained('lab_request_items')->restrictOnDelete();
            $table->foreignId('analysis_catalog_id')->constrained('analysis_catalogs')->restrictOnDelete();
            // Instantanés : le catalogue corrigé plus tard ne réécrit pas un résultat.
            $table->string('designation_snapshot');
            $table->string('entry_mode', 30);
            $table->string('unit_snapshot', 60)->nullable();
            $table->string('reference_snapshot')->nullable();
            $table->text('value')->nullable();
            $table->json('selections')->nullable();
            $table->string('interpretation', 20)->nullable();
            $table->string('range_flag', 10)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->timestamp('critical_flagged_at')->nullable();
            $table->foreignId('critical_flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lab_request_item_id', 'analysis_catalog_id'], 'lab_results_item_analysis_unique');
        });

        Schema::create('lab_antibiograms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_request_item_id')->constrained('lab_request_items')->restrictOnDelete();
            $table->foreignId('analysis_catalog_id')->constrained('analysis_catalogs')->restrictOnDelete();
            $table->foreignId('bacterium_id')->constrained('lab_bacteria')->restrictOnDelete();
            $table->string('bacterium_name_snapshot', 200);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['lab_request_item_id', 'analysis_catalog_id', 'bacterium_id'], 'lab_antibiograms_unique');
        });

        Schema::create('lab_antibiogram_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_antibiogram_id')->constrained('lab_antibiograms')->cascadeOnDelete();
            $table->foreignId('antibiotic_id')->constrained('lab_antibiotics')->restrictOnDelete();
            $table->string('antibiotic_name_snapshot', 200);
            $table->string('interpretation', 1);
            $table->decimal('measure', 6, 2)->nullable();
            $table->string('measure_unit', 15)->default('mm');
            $table->timestamps();
            $table->unique(['lab_antibiogram_id', 'antibiotic_id'], 'lab_antibiogram_results_unique');
        });

        // Les analyses déjà rendues attendent la validation : elles n'ont jamais été validées.
        DB::table('lab_request_items')->whereNotNull('resulted_at')->update(['status' => 'COMPLETED']);

        // Le type du laboratoire historique devient un mode de saisie explicite.
        DB::table('analysis_catalogs')->whereNotNull('source_metadata')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->source_metadata, true);
                    $choices = json_decode((string) ($row->predefined_values ?? '[]'), true);
                    $mode = LabEntryMode::fromLegacyType(
                        is_array($metadata) ? ($metadata['type_name'] ?? null) : null,
                        is_array($choices) && count($choices) > 0,
                    );
                    if ($mode !== null) {
                        DB::table('analysis_catalogs')->where('id', $row->id)->update(['entry_mode' => $mode->value]);
                    }
                }
            });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        foreach (self::GRANTS as $code => $names) {
            $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

            DB::table('roles')->where('code', $code)->whereNull('deleted_at')->pluck('id')
                ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])));
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('lab_antibiogram_results');
        Schema::dropIfExists('lab_antibiograms');
        Schema::dropIfExists('lab_results');

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'conclusion', 'started_at', 'validated_at', 'returned_at', 'return_reason']);
        });

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });

        Schema::dropIfExists('lab_antibiotics');
        Schema::dropIfExists('lab_bacteria');
        Schema::dropIfExists('lab_bacterium_families');

        Cache::forget(Permission::CACHE_KEY);
    }
};
