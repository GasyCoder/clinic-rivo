<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-212 — qui a recommandé la clinique à un nouveau patient, et les bonus du
 * personnel.
 *
 * `patient_referrals` : une recommandation par patient, notée à sa première
 * arrivée ; le cadeau remis au recommandant y est tracé (qui, quand).
 *
 * `bonus_categories` : une règle par métier (chirurgien, médecin,
 * laboratoire…) — ce qu'elle compte (BonusMeasure), un seuil mensuel et un
 * montant fixe — et les membres du personnel concernés.
 *
 * `bonus_awards` : un bonus atteint, validé par les RH puis marqué versé. Le
 * nombre de patients, le seuil et le montant y sont figés : corriger la
 * catégorie ensuite ne réécrit aucun bonus. Le versement se fait hors RIVO.
 *
 * Un site en production ne rejoue plus `RolePermissionSeeder` (ADR-064), d'où
 * les droits enregistrés ici.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'patient_referrals.view' => 'Voir les recommandations de patients',
        'patient_referrals.create' => 'Enregistrer qui a recommandé un nouveau patient',
        'patient_referrals.gift' => 'Marquer remis le cadeau d’une recommandation',
        'bonus_categories.view' => 'Voir les catégories de bonus',
        'bonus_categories.create' => 'Créer une catégorie de bonus',
        'bonus_categories.update' => 'Modifier une catégorie de bonus',
        'bonus_categories.archive' => 'Archiver une catégorie de bonus',
        'bonus_categories.restore' => 'Restaurer une catégorie de bonus',
        'bonus_awards.view' => 'Voir les bonus du personnel',
        'bonus_awards.validate' => 'Valider un bonus atteint',
        'bonus_awards.pay' => 'Marquer un bonus versé',
        'bonus_awards.cancel' => 'Annuler un bonus validé',
    ];

    private const GRANTS = [
        'ADMINISTRATION' => [
            'bonus_categories.view', 'bonus_categories.create', 'bonus_categories.update',
            'bonus_categories.archive', 'bonus_categories.restore',
            'bonus_awards.view', 'bonus_awards.validate', 'bonus_awards.pay', 'bonus_awards.cancel',
            'patient_referrals.view', 'patient_referrals.gift',
        ],
        'RECEPTION' => ['patient_referrals.view', 'patient_referrals.create', 'patient_referrals.gift'],
    ];

    public function up(): void
    {
        Schema::create('patient_referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->unique()->constrained('patients')->restrictOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->restrictOnDelete();
            $table->string('source', 20)->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('partner_organization_id')->nullable()->constrained('partner_organizations')->restrictOnDelete();
            // Le nom tel qu'il était à la recommandation : une fiche renommée ensuite ne le réécrit pas.
            $table->string('referrer_name');
            $table->string('referrer_phone', 40)->nullable();
            $table->timestamp('referred_at')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_recorded_by_uuid')->nullable();
            $table->string('external_recorded_by_name', 150)->nullable();
            $table->timestamp('gift_given_at')->nullable();
            $table->foreignId('gift_given_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_gift_given_by_uuid')->nullable();
            $table->string('external_gift_given_by_name', 150)->nullable();
            $table->string('gift_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('bonus_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->string('measure', 40);
            $table->unsignedInteger('threshold');
            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name', 150)->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('bonus_category_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_category_id')->constrained('bonus_categories')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['bonus_category_id', 'employee_id'], 'bonus_category_employee_unique');
        });

        Schema::create('bonus_awards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bonus_category_id')->constrained('bonus_categories')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Le mois du bonus : toujours le premier jour.
            $table->date('period');
            // Instantanés : corriger la catégorie ensuite ne réécrit aucun bonus.
            $table->string('category_name', 120);
            $table->string('measure', 40);
            $table->unsignedInteger('patients_count');
            $table->unsignedInteger('threshold');
            $table->decimal('amount', 14, 2);
            $table->json('counted_patients')->nullable();
            $table->string('status', 20)->index();
            // Un seul bonus en vigueur par catégorie, employé et mois ; une annulation le libère.
            $table->string('active_key', 80)->nullable()->unique();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_validated_by_uuid')->nullable();
            $table->string('external_validated_by_name', 150)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_paid_by_uuid')->nullable();
            $table->string('external_paid_by_name', 150)->nullable();
            $table->string('payment_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name', 150)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['period', 'status']);
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

        Schema::dropIfExists('bonus_awards');
        Schema::dropIfExists('bonus_category_employees');
        Schema::dropIfExists('bonus_categories');
        Schema::dropIfExists('patient_referrals');

        Cache::forget(Permission::CACHE_KEY);
    }
};
