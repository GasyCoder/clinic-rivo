<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-205 — la sage-femme prescrit depuis la Maternité.
 *
 * `maternity_record_id` ne désigne que les ordonnances écrites depuis un
 * dossier Maternité ; une ordonnance de consultation ou de séjour n'en a pas.
 * Rien n'est réécrit.
 *
 * Le profil sage-femme **recommande** désormais les droits de prescription : le
 * CDC range la sage-femme parmi les fonctions de la Médecine (§9), dont les
 * droits comprennent `prescriptions.*` (§15). Une recommandation n'accorde rien
 * (ADR-033) : un compte existant les reçoit quand le Super Administrateur
 * applique les recommandations de son profil, ou les lui accorde en exception.
 *
 * Les noms d'index et de clés sont écrits à la main : MySQL refuse un
 * identifiant de plus de 64 caractères.
 */
return new class extends Migration
{
    private const RECOMMENDED = [
        'prescriptions.view',
        'prescriptions.create',
        'prescriptions.cancel',
        'medicines.view',
        'stock.availability.view',
    ];

    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('maternity_record_id')->nullable()->after('hospital_stay_id');
            // L'index d'abord : la clé étrangère s'appuie sur lui au lieu d'en créer un second.
            $table->index('maternity_record_id', 'prescriptions_maternity_record_idx');
            $table->foreign('maternity_record_id', 'prescriptions_maternity_record_fk')
                ->references('id')->on('maternity_records')->restrictOnDelete();
        });

        $midwife = DB::table('professional_profiles')->where('code', 'MIDWIFE')->value('id');

        if ($midwife !== null) {
            $now = now();

            DB::table('permissions')
                ->whereIn('name', self::RECOMMENDED)
                ->pluck('id')
                ->each(fn ($permissionId) => DB::table('professional_profile_permissions')->insertOrIgnore([
                    'professional_profile_id' => $midwife,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $midwife = DB::table('professional_profiles')->where('code', 'MIDWIFE')->value('id');

        if ($midwife !== null) {
            DB::table('professional_profile_permissions')
                ->where('professional_profile_id', $midwife)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::RECOMMENDED)->pluck('id'))
                ->delete();
        }

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropForeign('prescriptions_maternity_record_fk');
            $table->dropIndex('prescriptions_maternity_record_idx');
            $table->dropColumn('maternity_record_id');
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
