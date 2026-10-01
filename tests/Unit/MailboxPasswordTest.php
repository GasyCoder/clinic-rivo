<?php

namespace Tests\Unit;

use App\Support\MailboxPassword;
use App\Support\ProfessionalEmailAddress;
use PHPUnit\Framework\TestCase;

/** ADR-190 — le premier mot de passe d'une boîte, et la forme d'une adresse. */
class MailboxPasswordTest extends TestCase
{
    public function test_a_password_is_long_mixed_and_never_ambiguous_to_read(): void
    {
        foreach (range(1, 50) as $ignored) {
            $password = MailboxPassword::generate();

            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[2-9]/', $password);
            $this->assertMatchesRegularExpression('/[#%+=?@_-]/', $password);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password, 'un caractère ambigu à la lecture');
        }

        $this->assertNotSame(MailboxPassword::generate(), MailboxPassword::generate());
    }

    public function test_the_local_part_rule(): void
    {
        foreach (['hery.rabe', 'h', 'hery-rabe_2', 'a1'] as $valid) {
            $this->assertTrue(ProfessionalEmailAddress::isValidLocalPart($valid), $valid);
        }

        foreach (['', '.hery', 'hery.', 'hery..rabe', 'héry', 'hery rabe', 'Hery', str_repeat('a', 65)] as $invalid) {
            $this->assertFalse(ProfessionalEmailAddress::isValidLocalPart($invalid), $invalid);
        }

        $this->assertSame('zephyr.haynes.craig', ProfessionalEmailAddress::slug('  Zéphyr   Haynes-Craig '));
    }

    /** Amendement du 2026-09-30 : prénom + nom trop longs donnent l'adresse la plus brève qui reste lisible. */
    public function test_a_long_name_gives_a_short_address(): void
    {
        $cases = [
            ['Zéphyr', 'Andrianina', 'zephyr.andrianina'],        // assez court : tel quel
            ['Jean-Paul', 'Rabe', 'jean.paul.rabe'],
            ['Latifah Olsen', 'Lee Park', 'latifah.lee'],          // premier prénom, premier nom
            ['Tahina Hery', 'RAKOTONDRAZAKA', 't.rakotondrazaka'], // initiale du prénom
            ['Fanomezantsoa Mialy', 'ANDRIAMANANTENASOAVINA', 'fanomezantsoa.a'], // initiale du nom
            ['', 'RAKOTOMALALA Tahina Hery Nirina', 'rakotomalala.tahina'],
            ['', '', ''],
        ];

        foreach ($cases as [$first, $last, $expected]) {
            $local = ProfessionalEmailAddress::localPartFor($first, $last);
            $this->assertSame($expected, $local, "$first $last");
            $this->assertLessThanOrEqual(ProfessionalEmailAddress::PREFERRED_LENGTH, mb_strlen($local));
            if ($local !== '') {
                $this->assertTrue(ProfessionalEmailAddress::isValidLocalPart($local), $local);
            }
        }
    }
}
