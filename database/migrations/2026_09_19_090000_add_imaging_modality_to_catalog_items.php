<?php

use App\Enums\ImagingModality;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-106 — la famille d'un examen d'imagerie devient une donnée du
 * catalogue, réglée par le Super Admin, jamais devinée.
 *
 * Nullable : un examen non classé reste visible dans un onglet « Non
 * classés » plutôt que d'atterrir au hasard dans l'un des deux autres. Une
 * absence de classement est une absence, pas un défaut de rangement.
 */
return new class extends Migration
{
    /**
     * Le pré-classement des examens déjà au catalogue, **code par code**.
     *
     * Aucun motif, aucun préfixe : `HOLTER-ECG` est un enregistrement
     * cardiaque et les deux `DOPPLER-*` sont des échographies, ce qu'aucune
     * règle sur le code ne dirait. Un code absent de cette liste n'est pas
     * classé — il apparaîtra comme tel, à régler au catalogue.
     */
    private const CLASSIFIED = [
        ImagingModality::Cardiology->value => [
            'ECG', 'ECG-EFFORT', 'HOLTER-ECG',
        ],
        ImagingModality::Ultrasound->value => [
            'ECHO-ABD', 'ECHO-ABD-PEL', 'ECHO-CARD', 'ECHO-MAMMAIRE', 'ECHO-MORPHO',
            'ECHO-OBS', 'ECHO-OBS-T1', 'ECHO-OBS-T2', 'ECHO-OBS-T3',
            'ECHO-PARTIES-MOLLES', 'ECHO-PEL', 'ECHO-PROSTATE', 'ECHO-RENAL',
            'ECHO-SCROTALE', 'ECHO-THYROIDE',
            // Un Doppler est une échographie, malgré son code.
            'DOPPLER-MI-ART', 'DOPPLER-MI-VEIN',
        ],
    ];

    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('imaging_modality', 20)
                ->nullable()
                ->after('reception_routing_mode')
                ->index();
        });

        foreach (self::CLASSIFIED as $modality => $codes) {
            DB::table('catalog_items')
                ->where('module', 'IMAGING')
                ->whereIn('code', $codes)
                ->update(['imaging_modality' => $modality]);
        }
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['imaging_modality']);
            $table->dropColumn('imaging_modality');
        });
    }
};
