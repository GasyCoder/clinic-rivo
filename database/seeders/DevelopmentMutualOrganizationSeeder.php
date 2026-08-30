<?php

namespace Database\Seeders;

use App\Models\MutualOrganization;
use Illuminate\Database\Seeder;
use LogicException;

class DevelopmentMutualOrganizationSeeder extends Seeder
{
    /**
     * Référentiel communiqué par le client pour les essais locaux.
     * « Sans mutuelle » est la grille STANDARD et « Avantage Personnel »
     * relève de la couverture RH : aucun des deux n'est un organisme.
     *
     * @var array<int, string>
     */
    public const ORGANIZATIONS = [
        'Funhece',
        'ADEFI',
        'G4S',
        'BOA',
        'BNI',
        'PAMF',
        'Personnels Ghislain',
        'Personnels Mamakely',
        'Personnels Maman',
        'ISPG',
        'Personnels Ghisbert',
        'TFC',
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException(
                'DevelopmentMutualOrganizationSeeder est strictement interdit hors des environnements local et testing.',
            );
        }

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException(
                'Le référentiel local des mutuelles doit être seedé dans une base de site clinique.',
            );
        }

        foreach (self::ORGANIZATIONS as $name) {
            $existing = MutualOrganization::withTrashed()
                ->where('normalized_name', MutualOrganization::normalize($name))
                ->first();

            // Ne jamais réactiver silencieusement un organisme archivé : la
            // restauration doit rester une décision explicite et auditée.
            if ($existing) {
                continue;
            }

            MutualOrganization::query()->create(['name' => $name, 'active' => true]);
        }
    }
}
