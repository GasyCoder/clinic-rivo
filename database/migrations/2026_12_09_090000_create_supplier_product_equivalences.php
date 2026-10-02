<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-241 — reconnaître le même produit sous deux noms.
 *
 *   product_synonyms                 le dictionnaire des abréviations du site
 *                                    (« pcm » = « paracetamol »), en plus de
 *                                    celui livré avec RIVO
 *   supplier_product_equivalences    ce qu'un humain a dit d'une paire : le
 *                                    même produit, deux produits, ou une
 *                                    proposition de l'IA qui attend sa décision
 *
 * Une paire désigne un produit d'un fournisseur par sa référence (ou son
 * libellé, à défaut), jamais par l'identifiant d'une ligne : relire un
 * catalogue recrée ses lignes, et la décision doit lui survivre.
 *
 * Le droit est accordé à aucun rôle d'un site : le Super Admin le reçoit à la
 * migration du portail (ADR-186).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'supplier_equivalences.manage' => 'Dire si deux produits fournisseurs sont le même (comparateur), régler le dictionnaire des abréviations et lancer le rapprochement par l’IA',
    ];

    public function up(): void
    {
        Schema::create('product_synonyms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('term', 40)->unique();
            $table->string('canonical', 60);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_product_equivalences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->cascadeOnDelete();
            $table->string('product_ref', 191);
            $table->string('label', 255);
            $table->foreignId('other_medicine_supplier_id')->nullable()->constrained('medicine_suppliers')->cascadeOnDelete();
            $table->string('other_product_ref', 191)->nullable();
            $table->string('other_label', 255)->nullable();
            // Une ligne de catalogue face à un produit de la clinique : seul
            // « ce n'est pas le même » s'y écrit — « le même » est le
            // rattachement de l'ADR-181, qui crée un prix d'achat.
            $table->foreignId('medicine_id')->nullable()->constrained('medicines')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('source', 20);
            $table->string('reason', 1000)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_decided_by_uuid')->nullable();
            $table->string('external_decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['medicine_supplier_id', 'product_ref', 'other_medicine_supplier_id', 'other_product_ref'], 'supplier_product_equivalences_pair_unique');
            $table->unique(['medicine_supplier_id', 'product_ref', 'medicine_id'], 'supplier_product_equivalences_medicine_unique');
            $table->index('status');
        });

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_equivalences');
        Schema::dropIfExists('product_synonyms');

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Cache::forget(Permission::CACHE_KEY);
    }
};
