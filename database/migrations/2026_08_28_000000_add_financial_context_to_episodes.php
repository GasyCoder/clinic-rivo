<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('financial_mode', 20)
                ->nullable()
                ->after('financial_status')
                ->index();
            $table->timestamp('financial_context_completed_at')
                ->nullable()
                ->after('financial_mode');
            $table->foreignId('financial_context_completed_by')
                ->nullable()
                ->after('financial_context_completed_at')
                ->constrained('users')
                ->restrictOnDelete();
        });

        Schema::create('episode_mutual_coverages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('mutual_organization_id')->constrained('mutual_organizations')->restrictOnDelete();
            $table->string('employer_name');
            $table->string('beneficiary_type', 30);
            $table->string('membership_number', 100);
            $table->uuid('organization_uuid_snapshot');
            $table->string('organization_name_snapshot');
            $table->decimal('coverage_rate_snapshot', 5, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['mutual_organization_id', 'membership_number'],
                'episode_mutual_coverage_membership_index',
            );
        });

        Schema::create('episode_staff_coverages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
        });

        Schema::create('episode_mutual_coverage_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_mutual_coverage_id')
                ->constrained('episode_mutual_coverages')
                ->restrictOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(
                ['episode_mutual_coverage_id', 'created_at'],
                'episode_mutual_attachments_coverage_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_mutual_coverage_attachments');
        Schema::dropIfExists('episode_staff_coverages');
        Schema::dropIfExists('episode_mutual_coverages');

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_context_completed_by');
            $table->dropIndex(['financial_mode']);
            $table->dropColumn([
                'financial_mode',
                'financial_context_completed_at',
            ]);
        });
    }
};
