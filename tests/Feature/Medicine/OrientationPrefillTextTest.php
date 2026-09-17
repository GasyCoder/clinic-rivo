<?php

namespace Tests\Feature\Medicine;

use App\Support\MedicineDossierPresenter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Un compte rendu d'imagerie est saisi en éditeur riche et stocké en HTML
 * (ADR-070). Le préremplissage d'une demande d'hospitalisation ou de
 * transfert le recopiait tel quel dans un `<textarea>`, où le balisage
 * s'affiche littéralement — et c'est ce texte-là qui partait au service
 * d'accueil.
 */
class OrientationPrefillTextTest extends TestCase
{
    private function convert(?string $html): ?string
    {
        $method = new ReflectionMethod(MedicineDossierPresenter::class, 'toPlainText');
        $method->setAccessible(true);

        return $method->invoke($this->app->make(MedicineDossierPresenter::class), $html);
    }

    public function test_it_turns_a_rich_text_report_into_readable_lines(): void
    {
        $this->assertSame(
            "UTERUS\n• Orientation : Antéversé\n• Volume",
            $this->convert('UTERUS<p>&bull; Orientation : Ant&eacute;vers&eacute;</p><p>&bull; Volume</p>'),
        );
    }

    /** Seuls `</p>` et `<br>` coupaient : une liste se lisait d'un bloc. */
    public function test_a_list_does_not_collapse_into_one_sentence(): void
    {
        $this->assertSame("Un\nDeux", $this->convert('<ul><li>Un</li><li>Deux</li></ul>'));
    }

    /** Un champ de texte n'a pas d'interlignage à restituer. */
    public function test_blank_paragraphs_do_not_pile_up(): void
    {
        $this->assertSame('Seul', $this->convert('<p></p><p></p><p>Seul</p>'));
    }

    /** Une absence reste une absence : jamais une chaîne vide. */
    public function test_an_empty_report_stays_null(): void
    {
        $this->assertNull($this->convert('<p></p>'));
        $this->assertNull($this->convert(null));
    }

    /** L'éditeur produit des espaces insécables que `trim()` seul laisse passer. */
    public function test_it_normalises_editor_whitespace(): void
    {
        $this->assertSame('Deux mots', $this->convert("<p>&nbsp; Deux   mots &nbsp;</p>"));
    }
}
