<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-193 — le mode maintenance d'un site, réglé depuis le portail par l'API du
 * site. Une ligne par maintenance, jamais effacée : qui l'a mise, quand elle a
 * commencé, quand elle devait finir, qui l'a levée et pourquoi.
 *
 * Deux droits, accordés à aucun rôle du site par défaut : le Super Administrateur
 * du portail les reçoit par la synchronisation de l'ADR-186 ; il accorde
 * `app_maintenance.bypass` au compte qui doit vérifier le site pendant
 * l'intervention (le technicien informatique, par exemple).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'app_maintenance.update' => 'Mettre un site en maintenance, la programmer ou la lever',
        'app_maintenance.bypass' => 'Utiliser le site pendant sa maintenance',
    ];

    public function up(): void
    {
        Schema::create('site_maintenances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->text('message')->nullable();
            $table->timestamp('starts_at');
            // Vide : le site reste fermé jusqu'à ce qu'on lève la maintenance.
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name', 150)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name', 150)->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_lifted_by_uuid')->nullable();
            $table->string('external_lifted_by_name', 150)->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamps();

            $table->index(['lifted_at', 'starts_at']);
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);

        Schema::dropIfExists('site_maintenances');
    }
};
