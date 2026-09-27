<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('user_id')
                ->constrained('hr_reference_values')->restrictOnDelete();
            $table->foreignId('job_title_id')->nullable()->after('department_id')
                ->constrained('hr_reference_values')->restrictOnDelete();
            $table->date('hire_date')->nullable()->after('birth_date');
            $table->string('birth_place')->nullable()->after('hire_date');
            $table->date('identity_document_issued_on')->nullable()->after('identity_document_number');
            $table->string('identity_document_issued_at')->nullable()->after('identity_document_issued_on');
            $table->string('diploma')->nullable()->after('children_count');
            $table->string('education_level')->nullable()->after('diploma');
            $table->text('children_details')->nullable()->after('education_level');
            $table->string('badge')->nullable()->after('children_details');
            $table->string('blouse')->nullable()->after('badge');
            $table->text('observation')->nullable()->after('address_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('job_title_id');
            $table->dropColumn([
                'hire_date',
                'birth_place',
                'identity_document_issued_on',
                'identity_document_issued_at',
                'diploma',
                'education_level',
                'children_details',
                'badge',
                'blouse',
                'observation',
            ]);
        });
    }
};
