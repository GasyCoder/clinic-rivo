<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-071: retires the ADR-069 upload/merge mechanism (never touching
 * EmploymentContract itself, the HR record — only the attached template and
 * its merge). Confirmed before writing this migration: no clinic deployment
 * had any row in employment_contract_templates, so nothing is migrated.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const PERMISSIONS = [
        'contract_templates.view', 'contract_templates.create', 'contract_templates.update',
        'contract_templates.archive', 'contract_templates.restore', 'contract_templates.download',
        'contracts.download',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('employment_contracts', 'contract_template_id')) {
            Schema::table('employment_contracts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('contract_template_id');
            });
        }

        if (Schema::hasColumn('employment_contracts', 'template_variables_snapshot')) {
            Schema::table('employment_contracts', function (Blueprint $table) {
                $table->dropColumn('template_variables_snapshot');
            });
        }

        Schema::dropIfExists('employment_contract_templates');

        DB::table('role_permissions')->whereIn(
            'permission_id',
            DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id'),
        )->delete();
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->delete();
        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        // Deliberately irreversible: the merge mechanism and its documents
        // are retired by decision (ADR-071), not by an incident — there is
        // nothing to restore into.
        throw new \RuntimeException(
            'Ce retrait (ADR-071) est irréversible par migration : recréer le mécanisme demanderait une nouvelle décision.',
        );
    }
};
