<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-108 — les feuilles d'échographie créées par les médecins d'un site.
 *
 * Les feuilles de la clinique (abdomino-pelvienne, pelvienne, obstétricales)
 * restent dans le code : ce sont les modèles papier transmis. Cette table
 * porte celles que les médecins ajoutent eux-mêmes depuis la fenêtre de
 * compte rendu. Propres à chaque site, comme les protocoles (ADR-111) : un
 * site n'écrit jamais dans la base d'un autre.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'imaging_templates.create', 'label' => 'Créer une feuille de compte rendu d’imagerie'],
        ['name' => 'imaging_templates.archive', 'label' => 'Retirer une feuille de compte rendu d’imagerie'],
    ];

    public function up(): void
    {
        Schema::create('imaging_report_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // Le corps clinique, en régions séparées par `<hr>` (colonne de
            // gauche, colonne de droite, cases pleine largeur).
            $table->longText('body_html');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // Par migration et non seulement dans le seeder : un site en
        // production ne rejoue plus `RolePermissionSeeder` (ADR-064).
        $medicine = DB::table('roles')->where('code', 'MEDICINE')->value('id');

        if ($medicine === null) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
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
        Schema::dropIfExists('imaging_report_templates');

        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
