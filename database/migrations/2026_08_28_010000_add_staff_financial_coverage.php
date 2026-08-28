<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // No legacy row is inferred from its label, code or module.
            $table->string('staff_coverage_policy', 40)
                ->default('UNCLASSIFIED')
                ->after('reception_routing_mode')
                ->index();
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->string('staff_coverage_policy', 40)
                ->default('UNCLASSIFIED')
                ->after('tariff_category');
            $table->decimal('staff_covered_amount', 15, 2)
                ->nullable()
                ->after('coverage_amount');
            $table->decimal('staff_block_credit_used', 15, 2)
                ->nullable()
                ->after('staff_covered_amount');
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->string('staff_coverage_policy', 40)
                ->default('UNCLASSIFIED')
                ->after('tariff_category');
            $table->decimal('staff_covered_amount', 15, 2)
                ->default(0)
                ->after('coverage_amount');
            $table->decimal('staff_block_credit_used', 15, 2)
                ->default(0)
                ->after('staff_covered_amount');
            $table->string('idempotency_key', 191)->nullable()->after('source_uuid');
            $table->unique('idempotency_key', 'billable_items_idempotency_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('financial_mode', 20)->nullable()->after('currency')->index();
            $table->decimal('staff_covered_amount', 15, 2)
                ->default(0)
                ->after('coverage_amount');
            $table->decimal('staff_block_credit_used', 15, 2)
                ->default(0)
                ->after('staff_covered_amount');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('staff_coverage_policy', 40)
                ->default('UNCLASSIFIED')
                ->after('gross_line_total');
            $table->decimal('staff_covered_amount', 15, 2)
                ->default(0)
                ->after('coverage_amount');
            $table->decimal('staff_block_credit_used', 15, 2)
                ->default(0)
                ->after('staff_covered_amount');
        });

        Schema::create('staff_block_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('movement_type', 30);
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('billable_item_id')->nullable()->constrained('billable_items')->restrictOnDelete();
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->unique()
                ->constrained('staff_block_credit_movements')
                ->restrictOnDelete();
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('idempotency_key', 191)->unique();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(
                ['billable_item_id', 'movement_type'],
                'staff_block_movement_billable_type_unique',
            );
            $table->index(
                ['employee_id', 'created_at'],
                'staff_block_movement_employee_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_block_credit_movements');

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn([
                'staff_coverage_policy', 'staff_covered_amount', 'staff_block_credit_used',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['financial_mode']);
            $table->dropColumn([
                'financial_mode', 'staff_covered_amount', 'staff_block_credit_used',
            ]);
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->dropUnique('billable_items_idempotency_unique');
            $table->dropColumn([
                'staff_coverage_policy', 'staff_covered_amount',
                'staff_block_credit_used', 'idempotency_key',
            ]);
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'staff_coverage_policy', 'staff_covered_amount', 'staff_block_credit_used',
            ]);
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['staff_coverage_policy']);
            $table->dropColumn('staff_coverage_policy');
        });
    }
};
