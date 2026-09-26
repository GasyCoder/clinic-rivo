<?php

namespace Tests\Unit\Webmail;

use App\Services\Webmail\OutgoingMessage;
use App\Support\Webmail\EmailHtmlSanitizer;
use PHPUnit\Framework\TestCase;

/** ADR-194 — le HTML d'un message reçu est rendu sûr ; celui qu'on écrit n'a qu'un jeu fermé de balises. */
class EmailHtmlSanitizerTest extends TestCase
{
    public function test_a_received_message_loses_everything_that_can_run_or_track(): void
    {
        $result = EmailHtmlSanitizer::forDisplay(
            '<style>@import url(https://x.test/a.css); body{color:red}</style>'
            .'<p onmouseover="x()" style="background:url(https://track.test/p.gif)">Texte</p>'
            .'<iframe src="https://x.test"></iframe><svg><script>x()</script></svg>'
            .'<a href="vbscript:msgbox(1)">a</a><a href="https://exemple.mg">b</a>'
            .'<img src="https://track.test/p.gif" srcset="https://track.test/2x.gif 2x"><img src="cid:inconnu">'
            .'<meta http-equiv="refresh" content="0;url=https://x.test">'
        );

        $html = $result['html'];
        foreach (['<style', 'onmouseover', '<iframe', '<svg', '<script', 'vbscript', 'track.test', '<meta'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('<a href="https://exemple.mg" target="_blank" rel="noopener noreferrer nofollow">b</a>', $html);
        $this->assertSame(2, $result['blocked_images'], 'l’image et le fond distants');
    }

    public function test_remote_images_are_shown_only_when_asked(): void
    {
        $result = EmailHtmlSanitizer::forDisplay('<img src="https://exemple.mg/logo.png">', [], true);

        $this->assertStringContainsString('https://exemple.mg/logo.png', $result['html']);
        $this->assertSame(0, $result['blocked_images']);
    }

    public function test_plain_text_is_escaped_and_its_links_made_clickable(): void
    {
        $html = EmailHtmlSanitizer::fromText("Bonjour <b>\nVoir https://exemple.mg/r?a=1&b=2");

        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('<a href="https://exemple.mg/r?a=1&amp;b=2"', $html);
    }

    public function test_the_written_body_keeps_formatting_and_nothing_else(): void
    {
        $html = EmailHtmlSanitizer::forSending(
            '<p style="text-align: center" class="x">Centré</p><table><tr><td>cellule</td></tr></table>'
            .'<a href="javascript:x()">mauvais</a><a href="mailto:a@exemple.mg" onclick="x()">bon</a><img src="x">'
        );

        $this->assertStringContainsString('<p style="text-align: center">Centré</p>', $html);
        $this->assertStringContainsString('cellule', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('<a href="mailto:a@exemple.mg">bon</a>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame("Centré\ncellulemauvaisbon", EmailHtmlSanitizer::toText($html));
    }

    public function test_recipients_accept_names_separators_and_report_what_is_wrong(): void
    {
        $this->assertSame(
            ['valid' => ['vola@exemple.mg', 'labo@exemple.mg'], 'invalid' => ['pas une adresse']],
            OutgoingMessage::recipients("Dr Vola <Vola@Exemple.mg>; labo@exemple.mg,\npas une adresse, vola@exemple.mg"),
        );
        $this->assertSame(['valid' => [], 'invalid' => []], OutgoingMessage::recipients(null));
    }

    public function test_a_built_message_is_threaded_and_its_body_cleaned(): void
    {
        $email = OutgoingMessage::build('soa@cbdc.mg', 'Soa Rakoto', [
            'to' => 'Dr Vola <A@exemple.mg>', 'cc' => 'b@exemple.mg', 'subject' => '  Re: Bilan ', 'body_html' => '<p>Oui</p><script>x()</script>',
        ], [], [['name' => 'n.pdf', 'type' => 'application/pdf', 'content' => '%PDF']], ['message_id' => 'orig@x.mg', 'references' => null]);

        $this->assertSame('Re: Bilan', $email->getSubject());
        $this->assertSame(['a@exemple.mg', 'Dr Vola'], [$email->getTo()[0]->getAddress(), $email->getTo()[0]->getName()], 'le nom saisi est gardé');
        $this->assertSame('b@exemple.mg', $email->getCc()[0]->getAddress());
        $this->assertStringNotContainsString('script', (string) $email->getHtmlBody());
        $this->assertStringEndsWith('@cbdc.mg', $email->getHeaders()->get('Message-ID')->getIds()[0]);
        $this->assertSame('<orig@x.mg>', $email->getHeaders()->get('References')->getBodyAsString());
        $this->assertCount(1, $email->getAttachments());
    }
}
