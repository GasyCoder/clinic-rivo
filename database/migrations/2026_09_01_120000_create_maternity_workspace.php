<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'maternity.view' => 'Voir la file et les dossiers Maternité',
        'maternity.create' => 'Ouvrir un dossier Maternité',
        'maternity.update' => 'Mettre à jour un dossier Maternité',
        'maternity.complete' => 'Clôturer une prise en charge Maternité',
        'maternity.prenatal.manage' => 'Renseigner le suivi prénatal',
        'maternity.labor.manage' => 'Renseigner le travail et sa surveillance',
        'maternity.delivery.manage' => 'Renseigner l’accouchement ou demander une césarienne',
        'maternity.newborn.manage' => 'Renseigner les nouveau-nés et leurs soins',
        'maternity.procedures.manage' => 'Enregistrer les actes du catalogue Maternité',
    ];

    public function up(): void
    {
        Schema::create('maternity_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('episode_orientation_id')->unique()->constrained('episode_orientations')->restrictOnDelete();
            $table->text('obstetric_context')->nullable();
            $table->json('pregnancy_data')->nullable();
            $table->json('prenatal_data')->nullable();
            $table->json('labor_data')->nullable();
            $table->json('delivery_data')->nullable();
            $table->json('newborn_data')->nullable();
            $table->text('maternal_care_notes')->nullable();
            $table->text('baby_care_notes')->nullable();
            $table->text('observations')->nullable();
            $table->text('transmission_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('maternity_procedures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('maternity_record_id')->constrained('maternity_records')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->uuid('catalog_item_uuid');
            $table->string('procedure_code', 80);
            $table->string('procedure_name');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['maternity_record_id', 'performed_at']);
        });

        $now = now();
        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name): array => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('maternity_procedures');
        Schema::dropIfExists('maternity_records');
        DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
    }
};
