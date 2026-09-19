<?php

namespace Tests\Unit;

use App\Services\Patient\PatientServiceNeeds;
use PHPUnit\Framework\TestCase;

/**
 * ADR-119 — le langage des filtres de besoin : ce que l'URL porte, et la clé
 * canonique d'une combinaison de services.
 */
class PatientServiceNeedsTest extends TestCase
{
    public function test_no_value_or_all_means_no_filter(): void
    {
        $this->assertNull(PatientServiceNeeds::parse(null));
        $this->assertNull(PatientServiceNeeds::parse(''));
        $this->assertNull(PatientServiceNeeds::parse('  '));
        $this->assertNull(PatientServiceNeeds::parse('ALL'));
        $this->assertNull(PatientServiceNeeds::parse('all'));
    }

    public function test_none_is_the_empty_combination_not_the_absence_of_a_filter(): void
    {
        // « Aucun de ces services » est une vraie réponse : la confondre avec
        // « pas de filtre » ferait d'un clic sur cette case un retour à tous.
        $this->assertSame([], PatientServiceNeeds::parse('NONE'));
        $this->assertSame([], PatientServiceNeeds::parse('none'));
    }

    public function test_a_combination_is_read_in_the_canonical_order_whatever_the_url_says(): void
    {
        $this->assertSame(['MEDICINE'], PatientServiceNeeds::parse('MEDICINE'));
        $this->assertSame(['MEDICINE', 'CARE'], PatientServiceNeeds::parse('CARE,MEDICINE'));
        $this->assertSame(['MEDICINE', 'CARE', 'PHARMACY'], PatientServiceNeeds::parse('pharmacy, care ,medicine'));
        $this->assertSame(['CARE', 'PHARMACY'], PatientServiceNeeds::parse('PHARMACY,CARE,CARE'));
    }

    public function test_an_unknown_service_filters_nothing_rather_than_showing_an_empty_list(): void
    {
        // Un signet périmé doit montrer les patients, pas une liste vide qui
        // se lirait « personne n'attend ».
        $this->assertNull(PatientServiceNeeds::parse('LABORATORY'));
        $this->assertNull(PatientServiceNeeds::parse('MEDICINE,LABORATORY'));
        $this->assertNull(PatientServiceNeeds::parse('MEDICINE;CARE'));
    }

    public function test_the_key_of_a_combination_is_stable_and_none_for_the_empty_one(): void
    {
        $this->assertSame('MEDICINE', PatientServiceNeeds::key(['MEDICINE']));
        $this->assertSame('MEDICINE,CARE', PatientServiceNeeds::key(['CARE', 'MEDICINE']));
        $this->assertSame('MEDICINE,CARE,PHARMACY', PatientServiceNeeds::key(['PHARMACY', 'CARE', 'MEDICINE']));
        $this->assertSame('NONE', PatientServiceNeeds::key([]));
    }

    public function test_a_key_read_back_from_the_url_is_the_key_that_was_written(): void
    {
        foreach (PatientServiceNeeds::combinations() as $services) {
            $key = PatientServiceNeeds::key($services);

            $this->assertSame($services, PatientServiceNeeds::parse($key), "La clé {$key} ne se relit pas telle quelle.");
        }
    }

    public function test_the_seven_combinations_cover_every_non_empty_subset_exactly_once(): void
    {
        $combinations = PatientServiceNeeds::combinations();
        $keys = array_map(fn (array $services) => PatientServiceNeeds::key($services), $combinations);

        $this->assertCount(7, $combinations);
        $this->assertCount(7, array_unique($keys));
        $this->assertNotContains('NONE', $keys);
    }
}
