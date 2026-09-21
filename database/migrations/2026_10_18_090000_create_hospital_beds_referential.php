<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-164 — services, chambres et lits de chaque site.
 *
 * Le référentiel appartient à la base du site (ADR-001) et se règle depuis le
 * portail, par l'API du site (ADR-004). Un lit est occupé parce qu'un séjour
 * en cours le porte : l'occupation n'est jamais saisie, elle se lit.
 * `hospital_stays.bed_active_key` vaut `BED_{id}` tant que le séjour est actif
 * et nul ensuite ; son index unique interdit, en base, que deux séjours en cours
 * occupent le même lit — même à deux clics simultanés.
 *
 * Les colonnes texte `service` et `room_bed` des séjours restent l'instantané
 * lu par tous les écrans : un lit renommé plus tard ne réécrit pas l'historique.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'hospital_beds.view' => 'Voir les services, chambres et lits d’un site et leur occupation',
        'hospital_beds.create' => 'Créer un service, une chambre (avec son nombre de lits) ou un lit',
        'hospital_beds.update' => 'Renommer un service, une chambre ou un lit, et mettre un lit hors service',
        'hospital_beds.archive' => 'Archiver un service, une chambre ou un lit libre',
        'hospital_beds.restore' => 'Restaurer un service, une chambre ou un lit archivé',
    ];

    public function up(): void
    {
        Schema::create('hospital_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('normalized_name', 150)->unique();
            $table->string('care_level', 20)->default('STANDARD');
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('hospital_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_service_id')->constrained('hospital_services')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->unique(['hospital_service_id', 'normalized_name']);
        });

        Schema::create('hospital_beds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_room_id')->constrained('hospital_rooms')->restrictOnDelete();
            $table->string('label', 50);
            $table->string('normalized_label', 50);
            $table->timestamp('out_of_service_at')->nullable();
            $table->string('out_of_service_reason', 500)->nullable();
            $table->foreignId('out_of_service_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_out_of_service_by_uuid')->nullable();
            $table->string('external_out_of_service_by_name', 150)->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->unique(['hospital_room_id', 'normalized_label']);
        });

        Schema::table('hospital_stays', function (Blueprint $table) {
            $table->foreignId('hospital_bed_id')->nullable()->after('room_bed')->constrained('hospital_beds')->restrictOnDelete();
            $table->string('bed_active_key', 40)->nullable()->unique()->after('hospital_bed_id');
        });

        Schema::table('hospital_stay_movements', function (Blueprint $table) {
            $table->foreignId('hospital_bed_id')->nullable()->after('room_bed')->constrained('hospital_beds')->restrictOnDelete();
        });

        $now = now();
        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name): array => compact('name', 'label') + ['created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Référentiel central : SUPER_ADMIN du portail seulement (ADR-027). Sur
        // un site, le rôle existe sans permission ; l'API revérifie les droits
        // transmis par le portail.
        $superAdmin = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($superAdmin && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id')
                    ->map(fn (int $id): array => ['role_id' => $superAdmin, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                    ->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Schema::table('hospital_stay_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hospital_bed_id');
        });

        Schema::table('hospital_stays', function (Blueprint $table) {
            $table->dropUnique(['bed_active_key']);
            $table->dropColumn('bed_active_key');
            $table->dropConstrainedForeignId('hospital_bed_id');
        });

        Schema::dropIfExists('hospital_beds');
        Schema::dropIfExists('hospital_rooms');
        Schema::dropIfExists('hospital_services');

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);
    }
};
