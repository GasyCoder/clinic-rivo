<?php

namespace Tests\Unit\Services\Medicine;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClinicalRichTextSanitizerTest extends TestCase
{
    #[Test]
    public function it_keeps_only_the_clinical_formatting_allowlist(): void
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);

        $html = $sanitizer->sanitize(
            '<p onclick="attack()"><b>Douleur</b> <span style="background: yellow">aiguë</span><script>attack()</script></p><ul><li>Fièvre</li></ul>',
        );

        $this->assertSame(
            '<p><strong>Douleur</strong> <mark>aiguë</mark></p><ul><li>Fièvre</li></ul>',
            $html,
        );
    }

    #[Test]
    public function plain_text_entities_are_not_double_encoded_when_projected_again(): void
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);

        $this->assertSame('Douleur &amp; fièvre', $sanitizer->toSafeHtml('Douleur & fièvre'));
        $this->assertSame('Douleur &amp; fièvre', $sanitizer->toSafeHtml('Douleur &amp; fièvre'));
        $this->assertSame('&lt;script&gt;', $sanitizer->toSafeHtml('&lt;script&gt;'));
    }
}
