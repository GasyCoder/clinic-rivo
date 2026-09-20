<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-151 — un droit se cherche sous le nom que l'écran lui donne.
 *
 * Le bouton « Prononcer la sortie » d'un séjour est gouverné par
 * `medical_discharge.create`, dont le libellé disait « Prononcer une sortie
 * médicale » et qui vit dans la catégorie « Sortie médicale ». Chercher
 * « hospitalisation » dans le catalogue ne le trouvait donc jamais : depuis le
 * portail, on ne pouvait pas retirer un droit qu'on voyait pourtant à l'œuvre.
 *
 * Trois droits agissent dans plusieurs modules depuis les ADR-113, ADR-114,
 * ADR-147 et ADR-148. Leur libellé le dit désormais — le `name` ne change
 * jamais (ADR-101), lui seul étant écrit dans le code.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const LABELS = [
        'consultations.create' => 'Ouvrir une consultation : prendre un patient en charge (file Médecine) ou ouvrir une visite de service (hospitalisation)',
        'diagnoses.create' => 'Poser un diagnostic (consultation, et sortie d’hospitalisation)',
        'medical_discharge.create' => 'Prononcer une sortie médicale (consultation, sortie d’hospitalisation, pédiatrie)',
    ];

    public function up(): void
    {
        foreach (self::LABELS as $name => $label) {
            DB::table('permissions')->where('name', $name)->update([
                'label' => $label,
                'updated_at' => now(),
            ]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        // Les libellés d'origine ne décrivaient qu'un module sur trois : les
        // rétablir remettrait le droit hors de portée de la recherche.
    }
};
