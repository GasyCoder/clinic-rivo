<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_reference_values', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('position');
        });

        $now = now();
        $leaveTypes = [
            'ANNUAL_LEAVE' => ['Congé annuel', true, 30, false],
            'PERMISSION' => ['Permission', false, null, false],
            'SICK_LEAVE' => ['Congé maladie', false, null, true],
            'EXCEPTIONAL_LEAVE' => ['Congé exceptionnel', false, null, false],
            'MATERNITY_LEAVE' => ['Congé maternité', false, null, true],
            'UNPAID_LEAVE' => ['Congé sans solde', false, null, false],
            'OTHER' => ['Autre', false, null, false],
        ];

        foreach (array_values($leaveTypes) as $position => $definition) {
            $code = array_search($definition, $leaveTypes, true);
            DB::table('hr_reference_values')->insert([
                'uuid' => (string) Str::uuid(),
                'type' => 'LEAVE_TYPE',
                'code' => $code,
                'label' => $definition[0],
                'active' => true,
                'position' => $position,
                'metadata' => json_encode([
                    'consumes_annual_balance' => $definition[1],
                    'annual_quota_days' => $definition[2],
                    'max_days_per_request' => null,
                    'requires_attachment' => $definition[3],
                    'requires_approval' => true,
                    'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('interim_employee_id')
                ->constrained('hr_reference_values')->restrictOnDelete();
            $table->decimal('projected_remaining_days_snapshot', 6, 2)->nullable()
                ->after('remaining_days_snapshot');
            $table->decimal('annual_quota_snapshot', 6, 2)->nullable()
                ->after('projected_remaining_days_snapshot');
            $table->string('day_count_method_snapshot', 40)->nullable()
                ->after('annual_quota_snapshot');
            $table->boolean('consumes_balance_snapshot')->default(false)
                ->after('day_count_method_snapshot');
            $table->boolean('requires_approval_snapshot')->default(true)
                ->after('consumes_balance_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn([
                'projected_remaining_days_snapshot',
                'annual_quota_snapshot',
                'day_count_method_snapshot',
                'consumes_balance_snapshot',
                'requires_approval_snapshot',
            ]);
        });

        DB::table('hr_reference_values')->where('type', 'LEAVE_TYPE')->delete();

        Schema::table('hr_reference_values', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
