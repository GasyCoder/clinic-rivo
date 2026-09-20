<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-146 — un bébé consigné en Maternité a son identité dès la saisie.
 *
 * Jusqu'ici une fiche de nouveau-né ne recevait un `uuid` qu'au moment où un dossier patient en dépendait. Le
 * bébé se retrouve désormais dans l'arborescence de sa mère avant d'être patient : chaque fiche déjà remplie
 * reçoit donc son identité, sans toucher à aucune autre donnée. Une fiche qui en avait déjà une (un bébé relié à
 * son dossier patient) la garde ; une fiche vide n'en reçoit pas — ce n'est pas un nouveau-né consigné.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('maternity_records')->whereNotNull('newborn_data')->orderBy('id')->each(function ($row): void {
            $data = json_decode((string) $row->newborn_data, true);

            if (! is_array($data) || ! is_array($data['newborns'] ?? null)) {
                return;
            }

            $changed = false;

            foreach ($data['newborns'] as $index => $newborn) {
                if (! is_array($newborn) || filled($newborn['uuid'] ?? null)) {
                    continue;
                }

                $filled = collect($newborn)->contains(fn ($value) => $value !== null && $value !== '');

                if ($filled) {
                    $data['newborns'][$index]['uuid'] = (string) Str::uuid();
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('maternity_records')->where('id', $row->id)->update([
                    'newborn_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Une identité donnée à une fiche ne se retire pas : un dossier patient a pu s'y rattacher.
    }
};
