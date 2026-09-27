<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-107 — le registre des décès et son acte de constatation.
 *
 * `medical_discharges` porte déjà l'heure, le lieu et les causes d'un décès
 * (ADR-035). Ce sont les éléments de la décision médicale, consignés dans la
 * consultation. L'acte de constatation est un document distinct : il est
 * signé par un médecin, à une date qui lui est propre, et il est remis à la
 * famille. Le confondre avec la sortie médicale reviendrait à dire qu'un
 * décès prononcé est un acte établi — deux faits différents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('death_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Un passage ne peut porter qu'un acte : le second serait un
            // doublon d'état civil, pas une correction.
            $table->foreignId('episode_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_discharge_id')->nullable()->constrained()->nullOnDelete();

            // Repris de la sortie médicale, puis corrigeables ici : c'est le
            // médecin qui constate qui signe ce qu'il écrit.
            $table->dateTime('death_occurred_at');
            $table->string('death_place');
            $table->text('death_causes');
            $table->text('observations')->nullable();

            $table->dateTime('constated_at');
            $table->foreignId('constated_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });

        // Voir le registre et établir l'acte ne sont pas la même autorité :
        // un agent peut avoir à suivre les passages concernés sans signer un
        // document médico-légal (même séparation qu'ADR-090).
        $permissions = [
            ['name' => 'death_records.view', 'label' => 'Consulter le registre des décès'],
            ['name' => 'death_records.create', 'label' => 'Établir un acte de constatation de décès'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // Enregistrées par migration et non seulement dans le seeder : un
        // site en production ne rejoue plus `RolePermissionSeeder` (ADR-064).
        $medicine = DB::table('roles')->where('code', 'MEDICINE')->value('id');

        if ($medicine === null) {
            return;
        }

        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('name', $permission['name'])->value('id');

            if ($id !== null) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $medicine, 'permission_id' => $id],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('death_records');

        $ids = DB::table('permissions')
            ->whereIn('name', ['death_records.view', 'death_records.create'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
