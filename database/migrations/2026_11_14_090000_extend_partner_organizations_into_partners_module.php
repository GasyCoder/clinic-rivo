<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-211 — le module Partenaires.
 *
 * Le référentiel `partner_organizations` ne portait qu'un nom. Il devient la
 * fiche d'un partenaire, Médical (une personne : nom, prénom, métier…) ou Autre
 * (un organisme ou une personne : nom ou identité). Les partenaires déjà
 * enregistrés (ISPSG, TsaraShop) sont des organismes : ils deviennent « Autre ».
 *
 * L'adresse se choisit dans le référentiel d'adresses du site (ADR-042), comme
 * pour un employé ou un patient ; `address` en garde le libellé.
 *
 * `patient_id` relie une fiche médicale au dossier patient de la même personne
 * quand elle vient se faire soigner : l'accueil la retrouve sans ressaisie. Un
 * lien d'identité, jamais une prise en charge (ADR-051).
 *
 * Les droits d'écriture n'existaient pas : seule la lecture était accordée à la
 * Réception. Ils vont à l'Administration ; le Super Admin du portail les reçoit
 * à la migration (ADR-186). Un site en production ne rejoue plus
 * `RolePermissionSeeder` (ADR-064), d'où cette migration.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'partner_organizations.view' => 'Voir les partenaires',
        'partner_organizations.create' => 'Ajouter un partenaire',
        'partner_organizations.update' => 'Modifier un partenaire',
        'partner_organizations.archive' => 'Archiver un partenaire',
        'partner_organizations.restore' => 'Restaurer un partenaire archivé',
    ];

    private const ADMINISTRATION = [
        'partner_organizations.view',
        'partner_organizations.create',
        'partner_organizations.update',
        'partner_organizations.archive',
        'partner_organizations.restore',
    ];

    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->string('category', 20)->default('OTHER')->after('uuid')->index();
            $table->string('last_name')->nullable()->after('name');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('profession', 30)->nullable()->after('first_name');
            $table->string('profession_detail', 100)->nullable()->after('profession');
            $table->string('sex', 1)->nullable()->after('profession_detail');
            $table->date('birth_date')->nullable()->after('sex');
            $table->string('phone', 40)->nullable()->after('birth_date');
            $table->string('email')->nullable()->after('phone');
            // L'adresse vient du référentiel du site (ADR-042) ; `address` en garde
            // le libellé en instantané, comme pour un dossier employé.
            $table->foreignId('address_entry_id')->nullable()->after('email');
            $table->foreign('address_entry_id', 'partner_organizations_address_fk')
                ->references('id')->on('address_entries')->restrictOnDelete();
            $table->string('address')->nullable()->after('address_entry_id');
            $table->text('notes')->nullable()->after('address');
            $table->foreignId('patient_id')->nullable()->after('notes');
            $table->unique('patient_id', 'partner_organizations_patient_unique');
            $table->foreign('patient_id', 'partner_organizations_patient_fk')
                ->references('id')->on('patients')->restrictOnDelete();
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionIds = DB::table('permissions')->whereIn('name', self::ADMINISTRATION)->pluck('id');

        DB::table('roles')->where('code', 'ADMINISTRATION')->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $removed = array_diff(array_keys(self::PERMISSIONS), ['partner_organizations.view']);
        $ids = DB::table('permissions')->whereIn('name', $removed)->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('permissions')->where('name', 'partner_organizations.view')->update(['label' => 'Voir les organismes partenaires']);

        $hasAddressEntry = Schema::hasColumn('partner_organizations', 'address_entry_id');
        // SQLite (le portail, le banc local) ne retire une clé étrangère que par
        // sa colonne ; MySQL par le nom qu'elle a reçu ci-dessus.
        $byColumn = DB::getDriverName() === 'sqlite';

        Schema::table('partner_organizations', function (Blueprint $table) use ($hasAddressEntry, $byColumn) {
            $table->dropForeign($byColumn ? ['patient_id'] : 'partner_organizations_patient_fk');
            if ($hasAddressEntry) {
                $table->dropForeign($byColumn ? ['address_entry_id'] : 'partner_organizations_address_fk');
            }
            $table->dropUnique('partner_organizations_patient_unique');
            $table->dropIndex(['category']);
            $table->dropColumn(array_merge([
                'category', 'last_name', 'first_name', 'profession', 'profession_detail',
                'sex', 'birth_date', 'phone', 'email', 'address', 'notes', 'patient_id',
            ], $hasAddressEntry ? ['address_entry_id'] : []));
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
