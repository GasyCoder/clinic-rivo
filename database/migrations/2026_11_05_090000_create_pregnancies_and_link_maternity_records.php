<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pregnancies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('status', 20)->default('ONGOING');
            $table->date('last_menstrual_period')->nullable();
            $table->date('estimated_due_date')->nullable();
            $table->string('dating_method', 30)->nullable();
            $table->dateTime('dating_confirmed_at')->nullable();
            $table->foreignId('dating_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('dating_correction_reason')->nullable();
            $table->unsignedTinyInteger('gravidity')->nullable();
            $table->unsignedTinyInteger('parity')->nullable();
            $table->text('risk_factors')->nullable();
            $table->date('started_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['patient_id', 'estimated_due_date']);
        });

        Schema::table('maternity_records', function (Blueprint $table) {
            // Nullable volontairement : les anciens dossiers ne sont pas
            // regroupés sans preuve qu'ils concernent la même grossesse.
            $table->foreignId('pregnancy_id')->nullable()->after('episode_orientation_id')
                ->constrained('pregnancies')->restrictOnDelete();
            $table->unsignedTinyInteger('gestational_age_weeks')->nullable()->after('pregnancy_id');
            $table->unsignedTinyInteger('gestational_age_days')->nullable()->after('gestational_age_weeks');
            $table->index(['pregnancy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('maternity_records', function (Blueprint $table) {
            $table->dropIndex(['pregnancy_id', 'created_at']);
            $table->dropConstrainedForeignId('pregnancy_id');
            $table->dropColumn(['gestational_age_weeks', 'gestational_age_days']);
        });

        Schema::dropIfExists('pregnancies');
    }
};
