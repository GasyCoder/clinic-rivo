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
}
