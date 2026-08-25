<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutual_organizations', function (Blueprint $table) {
            $table->decimal('coverage_rate', 5, 2)
                ->default(100)
                ->after('normalized_name');
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->uuid('mutual_organization_uuid')->nullable()->after('tariff_category');
            $table->string('mutual_organization_name')->nullable()->after('mutual_organization_uuid');
            $table->decimal('coverage_rate', 5, 2)->nullable()->after('mutual_organization_name');
            $table->decimal('gross_amount', 15, 2)->nullable()->after('quantity');
            $table->decimal('coverage_amount', 15, 2)->default(0)->after('gross_amount');
            $table->decimal('patient_amount', 15, 2)->nullable()->after('coverage_amount');
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->uuid('mutual_organization_uuid')->nullable()->after('tariff_category');
            $table->string('mutual_organization_name')->nullable()->after('mutual_organization_uuid');
            $table->decimal('coverage_rate', 5, 2)->default(0)->after('mutual_organization_name');
            $table->decimal('gross_amount', 15, 2)->nullable()->after('total_amount');
            $table->decimal('coverage_amount', 15, 2)->default(0)->after('gross_amount');
            $table->decimal('patient_amount', 15, 2)->nullable()->after('coverage_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('mutual_organization_uuid')->nullable()->after('currency');
            $table->string('mutual_organization_name')->nullable()->after('mutual_organization_uuid');
            $table->decimal('coverage_rate', 5, 2)->nullable()->after('mutual_organization_name');
            $table->decimal('coverage_amount', 15, 2)->default(0)->after('discount_amount');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('gross_line_total', 15, 2)->nullable()->after('line_total');
            $table->decimal('coverage_rate', 5, 2)->default(0)->after('gross_line_total');
            $table->decimal('coverage_amount', 15, 2)->default(0)->after('coverage_rate');
        });

        DB::table('episode_service_requests')
            ->whereNotNull('unit_price')
            ->orderBy('id')
            ->each(function (object $request): void {
                $gross = Money::multiply((string) $request->quantity, (string) $request->unit_price);

                DB::table('episode_service_requests')->where('id', $request->id)->update([
                    'coverage_rate' => '0.00',
                    'gross_amount' => Money::fromMinor($gross),
                    'coverage_amount' => '0.00',
                    'patient_amount' => Money::fromMinor($gross),
                ]);
            });

        DB::table('billable_items')->orderBy('id')->each(function (object $item): void {
            DB::table('billable_items')->where('id', $item->id)->update([
                'gross_amount' => $item->total_amount,
                'coverage_amount' => '0.00',
                'patient_amount' => $item->total_amount,
            ]);
        });

        DB::table('invoice_lines')->orderBy('id')->each(function (object $line): void {
            DB::table('invoice_lines')->where('id', $line->id)->update([
                'gross_line_total' => $line->line_total,
                'coverage_amount' => '0.00',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['gross_line_total', 'coverage_rate', 'coverage_amount']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'mutual_organization_uuid', 'mutual_organization_name',
                'coverage_rate', 'coverage_amount',
            ]);
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->dropColumn([
                'mutual_organization_uuid', 'mutual_organization_name', 'coverage_rate',
                'gross_amount', 'coverage_amount', 'patient_amount',
            ]);
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'mutual_organization_uuid', 'mutual_organization_name', 'coverage_rate',
                'gross_amount', 'coverage_amount', 'patient_amount',
            ]);
        });

        Schema::table('mutual_organizations', function (Blueprint $table) {
            $table->dropColumn('coverage_rate');
        });
    }
};
