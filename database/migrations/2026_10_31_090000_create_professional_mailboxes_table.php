<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-190 — adresses email professionnelles.
 *
 * Côté site : l'adresse d'un employé et sa vie (demandée, active, suspendue,
 * refusée, annulée), jamais supprimée. Côté portail : ce que le portail a
 * réellement fait chez l'hébergeur, pour qu'une confirmation perdue vers le
 * site ne fasse jamais créer deux fois la même boîte.
 *
 * Les deux tables existent dans toutes les bases : un seul schéma (ADR-002).
 * Un site en production ne rejoue plus les seeders (ADR-064) : les droits
 * sont enregistrés ici.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'professional_emails.view' => 'Voir les adresses email professionnelles',
        'professional_emails.request' => 'Demander une adresse email professionnelle pour un employé',
        'professional_emails.create' => 'Créer une adresse email professionnelle chez l’hébergeur',
        'professional_emails.reject' => 'Refuser une demande d’adresse email professionnelle',
        'professional_emails.deactivate' => 'Suspendre une adresse email professionnelle',
        'professional_emails.activate' => 'Réactiver une adresse email professionnelle suspendue',
        'professional_emails.update' => 'Réinitialiser le mot de passe d’une adresse email professionnelle',
    ];

    /** Le RH du site voit et demande ; créer, refuser et suspendre restent au Super Admin du portail. */
    private const ADMINISTRATION = ['professional_emails.view', 'professional_emails.request'];

    public function up(): void
    {
        Schema::create('professional_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('address', 254);
            $table->string('status', 20)->index();
            // Réservés tant que l'adresse est ouverte : une adresse, une
            // boîte ; un employé, une adresse. Vidés quand elle est refusée ou annulée.
            $table->string('active_key', 254)->nullable()->unique();
            $table->unsignedBigInteger('employee_active_key')->nullable()->unique();
            $table->text('request_note')->nullable();
            $table->timestamp('requested_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_requested_by_uuid')->nullable();
            $table->string('external_requested_by_name', 150)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('external_decided_by_uuid')->nullable();
            $table->string('external_decided_by_name', 150)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->uuid('external_suspended_by_uuid')->nullable();
            $table->string('external_suspended_by_name', 150)->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('professional_mailbox_provisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('site_code', 10);
            $table->uuid('mailbox_uuid');
            $table->string('address', 254);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('host_created_at');
            $table->timestamp('site_confirmed_at')->nullable();
            // Suspendue chez l'hébergeur : un nouvel essai après une confirmation
            // perdue ne redemande pas la même chose à l'hébergeur.
            $table->timestamp('host_suspended_at')->nullable();
            $table->timestamps();
            $table->unique(['site_code', 'mailbox_uuid'], 'mailbox_provisions_site_mailbox_unique');
        });

        $now = now();
        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $roleId = DB::table('roles')->where('code', 'ADMINISTRATION')->whereNull('deleted_at')->value('id');
        if ($roleId) {
            DB::table('permissions')->whereIn('name', self::ADMINISTRATION)->pluck('id')
                ->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]));
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);

        Schema::dropIfExists('professional_mailbox_provisions');
        Schema::dropIfExists('professional_mailboxes');
    }
};
