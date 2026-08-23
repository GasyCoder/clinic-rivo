<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_mutual_coverages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('mutual_organization_id')->constrained('mutual_organizations')->restrictOnDelete();
            $table->string('employer_name');
            $table->string('beneficiary_type', 30);
            $table->string('membership_number', 100);
            $table->string('active_key')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_from');
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_until')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestamps();

            // A family contract may legitimately reuse the same membership
            // number, so this is searchable but deliberately not unique.
            // Explicit short names keep the migration compatible with
            // MySQL/MariaDB's 64-character identifier limit.
            $table->index(
                ['mutual_organization_id', 'membership_number'],
                'patient_mutual_coverage_membership_index',
            );
            $table->index(
                ['patient_id', 'effective_until'],
                'patient_mutual_coverage_active_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_mutual_coverages');
    }
};
