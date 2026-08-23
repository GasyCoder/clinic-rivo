<?php

namespace App\Support;

final class SurgeryReferenceData
{
    /** @return array<int, array{code: string, name: string}> */
    public static function procedures(): array
    {
        return [
            ['code' => 'SURG-ADENOME-PROSTATE', 'name' => 'Adénome prostatique'],
            ['code' => 'SURG-APPENDICITE', 'name' => 'Appendicite'],
            ['code' => 'SURG-BLESSURE-BALLE', 'name' => 'Blessure par balle'],
            ['code' => 'SURG-CURETAGE-UTERUS', 'name' => 'Curetage chirurgical de l’utérus'],
            ['code' => 'SURG-CYSTOSTOMIE', 'name' => 'Cystostomie'],
            ['code' => 'SURG-FIBROME-UTERIN', 'name' => 'Fibrome utérin'],
            ['code' => 'SURG-GEU', 'name' => 'Grossesse extra-utérine (GEU)'],
            ['code' => 'SURG-HEMATOCELE', 'name' => 'Hématocèle'],
            ['code' => 'SURG-HEMORROIDE', 'name' => 'Hémorroïde'],
            ['code' => 'SURG-HERNIE-LIGNE-BLANCHE', 'name' => 'Hernie de la ligne blanche'],
            ['code' => 'SURG-HYDROCELE', 'name' => 'Hydrocèle'],
            ['code' => 'SURG-HYDROSALPINX', 'name' => 'Hydrosalpinx'],
            ['code' => 'SURG-HYSTERECTOMIE', 'name' => 'Hystérectomie'],
            ['code' => 'SURG-KYSTE-OVARIEN', 'name' => 'Kyste ovarien'],
            ['code' => 'SURG-LAPAROTOMIE', 'name' => 'Laparotomie exploratrice'],
            ['code' => 'SURG-LIGATURE-TROMPES', 'name' => 'Ligature des trompes'],
            ['code' => 'SURG-LIPOME', 'name' => 'Lipome'],
            ['code' => 'SURG-LITHIASE-VESICALE', 'name' => 'Lithiase vésicale'],
            ['code' => 'SURG-CESARIENNE', 'name' => 'Opération césarienne'],
            ['code' => 'SURG-PYOSALPINX', 'name' => 'Pyosalpinx'],
            ['code' => 'SURG-REFECTION-PLAIES', 'name' => 'Réfection des plaies'],
            ['code' => 'SURG-SPLENECTOMIE', 'name' => 'Splénectomie'],
            ['code' => 'SURG-TRAUMA-ZEBU', 'name' => 'Traumatisme par encornement de zébu'],
            ['code' => 'SURG-EVACUATION', 'name' => 'Évacuation'],
            ['code' => 'SURG-OTHER', 'name' => 'Autres'],
        ];
    }

    /** @return array<int, array{code: string, name: string, category: string}> */
    public static function anesthesiaItems(): array
    {
        return [
            ['code' => 'ANESTH-ADRENALINE', 'name' => 'Adrénaline', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-AIGUILLE-PL', 'name' => 'Aiguille PL', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-BUPIVACAINE', 'name' => 'Bupivacaïne', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-EPHEDRINE', 'name' => 'Éphédrine', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-FENTANYL', 'name' => 'Fentanyl', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-FIL-1-0', 'name' => 'Fil 1/0', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-FIL-2-0', 'name' => 'Fil 2/0', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-FIL-3-0', 'name' => 'Fil 3/0', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-FIL-4-0', 'name' => 'Fil 4/0', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-FIL-PEAU', 'name' => 'Fil à peau', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-KETAMINE', 'name' => 'Kétamine', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-LAMES', 'name' => 'Lames', 'category' => 'MATERIAL'],
            ['code' => 'ANESTH-MORPHINE', 'name' => 'Morphine', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-PROPOFOL', 'name' => 'Propofol', 'category' => 'MEDICATION'],
            ['code' => 'ANESTH-RACHI', 'name' => 'Rachianesthésie', 'category' => 'TECHNIQUE'],
            ['code' => 'ANESTH-OTHER', 'name' => 'Autres', 'category' => 'OTHER'],
        ];
    }

    /** @return array{code: string, name: string, category: string}|null */
    public static function anesthesiaItem(string $code): ?array
    {
        foreach (self::anesthesiaItems() as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }
}
