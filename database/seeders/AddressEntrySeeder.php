<?php

namespace Database\Seeders;

use App\Models\AddressEntry;
use Illuminate\Database\Seeder;

/**
 * Localités de la zone Mahajanga/Boeny/Sofia fournies par le client pour
 * amorcer le référentiel d'adresses de la Réception, afin que la saisie se
 * fasse par sélection plutôt que par texte libre à chaque passage.
 */
class AddressEntrySeeder extends Seeder
{
    /** @var array<int, string> */
    private const LABELS = [
        'Ambahatra', 'Ambalafety', 'Ambalavy', 'Ambanja', 'Ambanirano', 'Ambatobe',
        'Ambatofotsy', 'Ambatomay', 'Ambatomilahatra', 'Ambavadiala', 'Ambinanibe',
        'Ambinany', 'Ambodimadiro', 'Ambodimanga', 'Ambodiriagna', 'Ambodisakoana',
        'Ambodisatrana', 'Ambohitoaka', 'Ambomahavelona', 'Ambovomboriky',
        'Ampampamena', 'Ampandroangisy', 'Ampanihy', 'Amparihibe', 'Amparihikely',
        'Ampasimaiky', 'Ampasimatera', 'Ampijoroana', 'Ampombibe I', 'Ampombimanangy',
        'Ampombitsihanaka', 'Ampomihantona', 'Ampondrabe', 'Ampondralava', 'Analabe',
        'Andilambe', 'Andilamihoatra', 'Andimbinialifotsy', 'Andongoza', 'Andraijoro',
        'Andranolava', 'Andranomadio', 'Andranomangatsiaka', 'Andranomavokely',
        'Andranomena', 'Andranomiditra', 'Ankazonibaboka', 'Ankiaka', 'Ankijanibe',
        'Ankijanimadiro', 'Ankiripika', 'Ankiririky', 'Ankisompy', 'Anovilava',
        'Antanamarina', 'Antanambao', 'Antananarivo', 'Antanandava', 'Antanimora',
        'Antanivaky', 'Antremahely', 'Antsakoamaro', 'Antsangabitika',
        'Antsatramanera', 'Antsirasira', 'Antsohihikely', 'Antsokomilaiky',
        'Anjiabe', 'Beagoago', 'Bebakoly', 'Befandriana', 'Befotaka', 'Bekobany',
        'Bekoratsaka', 'Bemakamba', 'Bemarivo', 'Bemokondry', 'Benavony',
        'Betaimborona', 'Betanantanana', 'Betaramahamay', 'Betrandraka',
        'Betsingiala', 'Bilaingindroa', 'Bongolava', 'Carière',
        'Croisement Malakialina', 'Komajia', 'Lehanja', 'Madirovalo', 'Mahajanga',
        'Mahatazana', 'Mahatsinjo', 'Mahiagogo', 'Maizina', 'Malakialina',
        'Mandrosoa', 'Mandrosoarivo', 'Manerinerina', 'Mangarivotra',
        'Manoroampango', 'Maraboalina', 'Maroampango', 'Marojia', 'Maromaniry',
        'Marovantaza', 'Marovitsika', 'Marovoay', 'Marozora', 'Masoantrasy',
        'Matsaborimena', 'Mevaranohely', 'Miadana', 'Miadanasoa', 'Miarinarivo',
        'Morarano', 'Port-Bergé', 'Sinjeny', 'Soberaka', 'Tanetilava',
        'Tsangambato', 'Tsangambitika', 'Tsarabanja', 'Tsarahasina',
        'Tsaramandroso', 'Tsaramandroso I', 'Tsaramandroso II', 'Tsaramandroso III',
        'Tsararivotra', 'Tsaratanana motretry', 'Tserempo', 'Tsimijaly',
        'Tsiningia', 'Tsinjofary', 'Tsinjorano', 'Tsinjovary', 'Ambalavelona',
        'Ambodiadabo', 'Anjiamarina', 'Ambodimanary', 'Mantsaborimena', 'Mariaha',
        'Marovato', 'Marandamba', 'Tsaratanana', 'Andongona', 'Antsiraka',
        'Ankarongana', 'Ankitrobaka', 'Ankazambo', 'Antsambalahy', 'Andranomeva',
        'Sambava', 'Ambondromamy', 'Ambalamanga', 'Analasarotra', 'Nosy be',
        'Tsarahonenana', 'Andoharano', 'Angoaka Sud', 'Mahajamba', 'Begara',
        'Tsinjoarivo', 'Ankijanimanga', 'Antsaronala', 'Ankamay', 'Beronono',
        'Antsiradrano', 'Ambalanjanakomby', 'Ambatoharanana', 'Ampandrana',
        'Andrevorevo', 'Antafiangita', 'Ambohimasoa', 'Ankaroabato',
        'Croisement base', 'Marosely', 'Ambararatilava', 'Ambodiazambo',
        'Amparimanonga', 'Vohemar', 'Antranomby', 'Tsimahajao', 'Ankirajibe',
        'Belinta', 'Croisement Antsohikely', 'Ambalanomby', 'Ambalamahogo',
        'Ambohitromby', 'Maroandambana', 'Betamotamo', 'Bealanana', 'Sarodrano',
        'Antsohihy', 'Sarobaratra', 'Maevatananahely', 'Croisement Marojia',
        'Benadraimavo', 'Andrafiabe', 'Ambatolampy', 'Ankazomena', 'Antsakay',
        'Kotsehina', 'Mandritsara', 'Tserepoko', 'Tamatave', 'Ambalakitata',
        'Bekitoto', 'Ambatomisikotra', 'Betsikanga',
    ];

    public function run(): void
    {
        // The list carries incidental repeats (same locality quoted more than
        // once by the client); normalized_label is unique, including against
        // archived rows, so an existence check keyed the same way the model
        // itself normalizes is what keeps this idempotent across re-runs.
        foreach (self::LABELS as $label) {
            $normalized = AddressEntry::normalize($label);

            if (AddressEntry::withTrashed()->where('normalized_label', $normalized)->exists()) {
                continue;
            }

            AddressEntry::create(['label' => $label, 'active' => true]);
        }
    }
}
