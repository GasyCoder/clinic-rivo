<?php

use App\Support\Hr\DefaultJobTitleDepartments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-194 — une fonction liste les départements où elle existe. Choisir
 * « Laboratoire » ne propose plus « Gardien ». Une fonction sans aucun lien
 * reste proposée partout : rien ne disparaît avant d'avoir été réglé.
 *
 * La proposition du CDC §9 est appliquée aux fonctions livrées qui n'ont
 * encore aucun département ; elle se corrige dans Paramètres RH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_title_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_title_id')->constrained('hr_reference_values')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('hr_reference_values')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['job_title_id', 'department_id']);
        });

        DefaultJobTitleDepartments::apply();
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_job_title_departments');
    }
};
