<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-108 — le titre de la feuille, et la modification d'une feuille ajoutée.
 *
 * Le bandeau bleu du compte rendu porte le titre exact de la feuille choisie
 * (« ÉCHOGRAPHIE OBSTÉTRICALE (2ème – 3ème TRIMESTRE) »), pas le nom de
 * l'examen du catalogue. C'est un **instantané** : renommer ou retirer la
 * feuille plus tard ne réécrit jamais un compte rendu déjà signé. Nul : aucune
 * feuille n'a été utilisée, le bandeau garde le nom de l'examen.
 */
return new class extends Migration
{
    private const PERMISSION = ['name' => 'imaging_templates.update', 'label' => 'Modifier une feuille de compte rendu d’imagerie'];

    public function up(): void
    {
        Schema::table('imaging_request_items', function (Blueprint $table): void {
            $table->string('report_sheet_title', 160)->nullable()->after('result_notes');
        });

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION['name']],
            self::PERMISSION + ['created_at' => now(), 'updated_at' => now()],
        );

        // Par migration : un site en production ne rejoue plus
        // `RolePermissionSeeder` (ADR-064).
        $medicine = DB::table('roles')->where('code', 'MEDICINE')->value('id');
        $id = DB::table('permissions')->where('name', self::PERMISSION['name'])->value('id');

        if ($medicine !== null && $id !== null) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $medicine, 'permission_id' => $id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('imaging_request_items', function (Blueprint $table): void {
            $table->dropColumn('report_sheet_title');
        });

        $id = DB::table('permissions')->where('name', self::PERMISSION['name'])->value('id');

        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
