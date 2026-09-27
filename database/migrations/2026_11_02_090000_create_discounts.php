<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-192 — les remises. Le CDC les prévoit (§12 `discounts.*`, §33.2 « avoirs /
 * remises autorisés », §34.2 règle 7) ; le propriétaire en a fixé les règles :
 *
 *   - une seule remise par facture, la plus avantageuse pour le patient ;
 *   - calculée sur la part patient, après mutuelle et prise en charge Personnel ;
 *   - VIP : un pourcentage ou un montant réglé avec les seuils VIP (module Patients VIP) ;
 *   - personnel : un pourcentage ou un montant réglé par site (paramètres) ;
 *   - une remise propre à un patient précis, décidée par une personne habilitée ;
 *   - des coupons à code, créés par site depuis le portail.
 *
 * Rien n'est supprimé : une remise appliquée se retire (tracée), un coupon
 * s'archive, une remise patient s'annule — chacun avec son auteur et sa date.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'discounts.view' => 'Voir les remises d’une facture et d’un patient',
        'discounts.create' => 'Appliquer ou retirer une remise sur une facture à encaisser',
        'discounts.approve' => 'Accorder ou annuler la remise propre à un patient',
        'discount_coupons.view' => 'Voir les coupons de remise',
        'discount_coupons.create' => 'Créer un coupon de remise',
        'discount_coupons.archive' => 'Archiver un coupon de remise',
    ];

    /** @var array<string, list<string>> */
    private const GRANTS = [
        // La Caisse applique la remise au moment d'encaisser (CDC §34.2 règle 13).
        'RECEPTION' => ['discounts.view', 'discounts.create'],
        // Une remise durable accordée à un patient est une dérogation : une personne habilitée.
        'ADMINISTRATION' => ['discounts.view', 'discounts.approve'],
    ];

    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->string('staff_discount_type', 10)->nullable()->after('bank_account');
            $table->decimal('staff_discount_value', 15, 2)->nullable()->after('staff_discount_type');
        });

        // La remise VIP vit avec les seuils qui font un patient VIP (ADR-133) : un seul module.
        Schema::table('patient_vip_settings', function (Blueprint $table): void {
            $table->string('discount_type', 10)->nullable()->after('window_months');
            $table->decimal('discount_value', 15, 2)->nullable()->after('discount_type');
        });

        Schema::create('discount_coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Unique pour toujours, archives comprises : un code qui a servi ne désigne jamais autre chose.
            $table->string('code', 40)->unique();
            $table->string('label', 150)->nullable();
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 15, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name', 150)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_archived_by_uuid')->nullable();
            $table->string('external_archived_by_name', 150)->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_discounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 15, 2);
            $table->text('reason');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'cancelled_at']);
        });

        Schema::create('invoice_discounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('source', 20);
            $table->string('label', 150);
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 15, 2);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->foreignId('discount_coupon_id')->nullable()->constrained('discount_coupons')->restrictOnDelete();
            $table->foreignId('patient_discount_id')->nullable()->constrained('patient_discounts')->restrictOnDelete();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remove_reason')->nullable();
            // Une seule remise en vigueur par facture : l'id de la facture tant qu'elle l'est, vide ensuite.
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            $table->timestamps();
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        foreach (self::GRANTS as $role => $permissions) {
            $roleIds = DB::table('roles')->where('code', $role)->whereNull('deleted_at')->pluck('id');
            $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
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
        Cache::forget(Permission::CACHE_KEY);

        Schema::dropIfExists('invoice_discounts');
        Schema::dropIfExists('patient_discounts');
        Schema::dropIfExists('discount_coupons');

        Schema::table('patient_vip_settings', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_value']);
        });

        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn(['staff_discount_type', 'staff_discount_value']);
        });
    }
};
