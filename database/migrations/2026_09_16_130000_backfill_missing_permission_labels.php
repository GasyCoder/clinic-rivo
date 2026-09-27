<?php

use App\Models\Permission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rend un libellé aux permissions qui n'en ont pas.
 *
 * Constaté sur `consultations.reopen` : la ligne existait avec son seul nom.
 * Une permission peut entrer en base par un chemin qui ne pose pas le
 * libellé — un seeder partiel, un ajout manuel — et le catalogue du portail
 * triait alors sur `null`, ce qui interrompait le rendu de l'écran entier.
 *
 * Le libellé officiel vient de `PermissionSeeder::PERMISSIONS`, la source du
 * catalogue. Pour un nom qui n'y figure pas — une permission créée depuis le
 * portail (ADR-101) dont le libellé aurait été perdu — on retombe sur le nom
 * lui-même : c'est ce que le code écrit, et cela reste lisible. Rien n'est
 * inventé, et aucun libellé existant n'est réécrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('permissions')
            ->whereNull('label')
            ->orWhere('label', '')
            ->get(['id', 'name']);

        foreach ($orphans as $permission) {
            DB::table('permissions')
                ->where('id', $permission->id)
                ->update([
                    'label' => PermissionSeeder::PERMISSIONS[$permission->name] ?? $permission->name,
                    'updated_at' => now(),
                ]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        // Rendre un libellé à `null` ne restaurerait rien : ce n'était pas
        // une décision, c'était une donnée manquante.
    }
};
