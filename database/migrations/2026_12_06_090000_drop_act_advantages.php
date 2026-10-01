<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait des avantages comptés à l'acte (ADR-226), à la demande du propriétaire : les
 * avantages des médecins se saisissent à la main, médecin par médecin (ADR-227).
 *
 * Retirés : advantage_articles, advantage_article_items, advantage_awards et
 * salary_payments.advantage_award_id. `employees.benefits_enabled` reste : c'est la case
 * « Avantages » qui ouvre la saisie pour une personne.
 *
 * Rien ne se perd en silence : si un avantage à l'acte a été validé ou versé, la migration
 * s'arrête et le dit. Une paie déjà payée garde sa ligne « Avantages à l'acte » figée dans
 * `salary_payments.lines`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('advantage_awards')) {
            $kept = DB::table('advantage_awards')->whereIn('status', ['VALIDATED', 'PAID'])->count();

            if ($kept > 0) {
                throw new RuntimeException("{$kept} avantage(s) à l'acte validé(s) ou versé(s) existent : payez ou annulez-les avant de retirer ce module (ADR-226).");
            }
        }

        if (Schema::hasColumn('salary_payments', 'advantage_award_id')) {
            Schema::table('salary_payments', function (Blueprint $table): void {
                $table->dropForeign(['advantage_award_id']);
            });
            Schema::table('salary_payments', function (Blueprint $table): void {
                $table->dropColumn('advantage_award_id');
            });
        }

        Schema::dropIfExists('advantage_awards');
        Schema::dropIfExists('advantage_article_items');
        Schema::dropIfExists('advantage_articles');
    }

    public function down(): void
    {
        Schema::create('advantage_articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->index();
            $table->string('source', 20);
            $table->decimal('unit_price', 12, 2);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();
        });

        Schema::create('advantage_article_items', function (Blueprint $table): void {
            $table->foreignId('advantage_article_id')->constrained('advantage_articles')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->primary(['advantage_article_id', 'catalog_item_id']);
        });

        Schema::create('advantage_awards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('beneficiary_type', 20);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('partner_organization_id')->nullable()->constrained('partner_organizations')->restrictOnDelete();
            $table->string('beneficiary_name');
            $table->date('period');
            $table->json('lines');
            $table->decimal('total_amount', 14, 2);
            $table->string('status', 20);
            $table->string('active_key')->nullable()->unique();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_validated_by_uuid')->nullable();
            $table->string('external_validated_by_name')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_paid_by_uuid')->nullable();
            $table->string('external_paid_by_name')->nullable();
            $table->string('payment_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['period', 'status']);
        });

        Schema::table('salary_payments', function (Blueprint $table): void {
            $table->foreignId('advantage_award_id')->nullable()->after('lines')->constrained('advantage_awards')->nullOnDelete();
        });
    }
};
