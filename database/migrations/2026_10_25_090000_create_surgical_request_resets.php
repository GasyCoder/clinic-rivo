<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-171 — réinitialiser un dossier du bloc saisi à tort, sans rien détruire.
 *
 * Chaque réinitialisation garde, avant d'effacer, l'instantané complet de ce
 * qui était saisi (programmation, équipe, entrée et sortie du bloc,
 * intervention, compte rendu, checklists, anesthésie…), son motif, son auteur
 * et son heure. Les lignes sont retirées du dossier, jamais perdues (ADR-010).
 *
 * Le droit `surgery.reset` est accordé au rôle SURGERY ; un site en production
 * ne rejoue plus `RolePermissionSeeder` (ADR-064), d'où l'insertion ici.
 */
return new class extends Migration
{
    private const PERMISSION = 'surgery.reset';

    private const LABEL = 'Réinitialiser un dossier de chirurgie saisi à tort (archivé, motif obligatoire)';

    /** @var list<string> */
    private const ROLES = ['SURGERY'];

    public function up(): void
    {
        Schema::create('surgical_request_resets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->string('previous_status', 32);
            $table->longText('snapshot');
            $table->text('reason');
            $table->foreignId('reset_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reset_at');
            $table->timestamps();

            $table->index(['surgical_request_id', 'reset_at']);
        });

        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::PERMISSION, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        DB::table('roles')->whereIn('code', self::ROLES)->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        DB::table('user_permissions')->where('permission_id', $id)->delete();
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        Schema::dropIfExists('surgical_request_resets');

        Cache::forget(Permission::CACHE_KEY);
    }
};
