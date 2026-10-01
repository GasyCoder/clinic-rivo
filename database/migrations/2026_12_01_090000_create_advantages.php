<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avantages à l'acte (module Bonus) et ouverture des avantages par personne.
 *
 * employees.benefits_enabled   la case « Avantages » de l'étape Rémunération ;
 *                              nulle = la fonction décide (module Fonctions)
 * advantage_articles           un article (ECHO, ECG, CHIR…) : les actes du catalogue qu'il
 *                              compte, s'il compte les actes réalisés ou les patients référés,
 *                              et son prix unitaire
 * advantage_awards             l'avantage d'une personne pour un mois, validé puis versé
 *                              (hors RIVO) ; quantités, prix et totaux figés
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('benefits_enabled')->nullable()->after('remuneration_amount');
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('advantage_awards');
        Schema::dropIfExists('advantage_article_items');
        Schema::dropIfExists('advantage_articles');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('benefits_enabled');
        });
    }
};
