<?php

namespace Tests\Feature\Webmail;

use App\Services\Webmail\ImapMailServer;
use App\Services\Webmail\OutgoingMessage;
use App\Services\Webmail\WebmailAuthenticationFailed;
use Tests\TestCase;

/**
 * ADR-195 — le vrai client IMAP/SMTP, contre un serveur de messagerie de test.
 *
 * Ne s'exécute que si un serveur est déclaré, jamais contre une boîte réelle :
 *
 *   docker run -d --name rivo-greenmail -p 127.0.0.1:3143:3143 -p 127.0.0.1:3025:3025 \
 *     -e GREENMAIL_OPTS='-Dgreenmail.setup.test.imap -Dgreenmail.setup.test.smtp -Dgreenmail.hostname=0.0.0.0
 *       -Dgreenmail.users=soa.rakoto:secret-boite@cbdc.mg -Dgreenmail.users.login=email' greenmail/standalone:2.1.2
 *   RIVO_WEBMAIL_TEST_SERVER=127.0.0.1 php artisan test tests/Feature/Webmail/ImapMailServerIntegrationTest.php
 */
class ImapMailServerIntegrationTest extends TestCase
{
    private const ADDRESS = 'soa.rakoto@cbdc.mg';

    private const PASSWORD = 'secret-boite';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('RIVO_WEBMAIL_TEST_SERVER') ?: null;
        if ($host === null) {
            $this->markTestSkipped('Aucun serveur de messagerie de test (RIVO_WEBMAIL_TEST_SERVER).');
        }

        config([
            'rivo.webmail.imap' => ['host' => $host, 'port' => 3143, 'encryption' => 'none', 'validate_cert' => false],
            'rivo.webmail.smtp' => ['host' => $host, 'port' => 3025, 'encryption' => 'none'],
            'rivo.webmail.timeout' => 10,
        ]);
    }

    public function test_a_wrong_password_is_refused_by_the_server(): void
    {
        $this->expectException(WebmailAuthenticationFailed::class);

        ImapMailServer::connect(self::ADDRESS, 'mauvais');
    }

    public function test_the_whole_cycle_against_a_real_server(): void
    {
        $server = ImapMailServer::connect(self::ADDRESS, self::PASSWORD);
        $marker = 'rivo'.bin2hex(random_bytes(4));

        // Envoi par SMTP, à soi-même, avec une pièce jointe.
        $upload = tempnam(sys_get_temp_dir(), 'rivo');
        file_put_contents($upload, '%PDF-1.4 test');
        $email = OutgoingMessage::build(self::ADDRESS, 'Soa Rakoto', [
            'to' => self::ADDRESS, 'subject' => "Résultats d’échographie {$marker}", 'body_html' => "<p>Bonjour <strong>docteur</strong>, {$marker}</p>",
        ], [], [['name' => 'bilan.pdf', 'type' => 'application/pdf', 'content' => '%PDF-1.4 test']]);
        $server->send($email);
        @unlink($upload);

        $uid = null;
        for ($attempt = 0; $attempt < 20 && $uid === null; $attempt++) {
            $uid = $server->uids('INBOX', ['text' => $marker])[0] ?? null;
            if ($uid === null) {
                usleep(250_000);
            }
        }
        $this->assertNotNull($uid, 'le message envoyé arrive dans la boîte');

        // Un texte accentué part en littéral IMAP avec son jeu de caractères : le
        // serveur l'accepte. (GreenMail ne décode pas le texte pour chercher ;
        // Dovecot, chez l'hébergeur, le fait.)
        $this->assertIsArray($server->uids('INBOX', ['text' => 'Échographie', 'unseen' => true]));

        $listing = $server->messages('INBOX', 1, 25, ['text' => $marker]);
        $this->assertSame(1, $listing['total']);
        $this->assertSame("Résultats d’échographie {$marker}", $listing['items'][0]['subject'], 'l’objet accentué est décodé');
        $this->assertFalse($listing['items'][0]['seen'], 'lister ne marque pas lu');
        $this->assertTrue($listing['items'][0]['has_attachments']);

        $message = $server->message('INBOX', $uid);
        $this->assertStringContainsString('<strong>docteur</strong>', (string) $message['html']);
        $this->assertSame('bilan.pdf', $message['attachments'][0]['name']);
        $this->assertFalse($server->messages('INBOX', 1, 25, ['text' => $marker])['items'][0]['seen'], 'lire le corps ne marque pas lu (PEEK)');
        $this->assertSame('%PDF-1.4 test', $server->attachment('INBOX', $uid, $message['attachments'][0]['part'])['content']);

        // Drapeaux et mot-clé (libellé).
        $server->flag('INBOX', [$uid], '\\Seen', true);
        $server->flag('INBOX', [$uid], '\\Flagged', true);
        $server->flag('INBOX', [$uid], 'rivotest0001', true);
        $item = $server->messages('INBOX', 1, 25, ['text' => $marker])['items'][0];
        $this->assertTrue($item['seen']);
        $this->assertTrue($item['flagged']);
        $this->assertContains('rivotest0001', $item['keywords']);
        $this->assertSame([$uid], $server->uids('INBOX', ['keyword' => 'rivotest0001', 'text' => $marker]));
        $this->assertSame([$uid], $server->uids('INBOX', ['flagged' => true, 'text' => $marker]));
        $this->assertSame([], $server->uids('INBOX', ['unseen' => true, 'text' => $marker]));

        // Dossiers : création, déplacement, dépôt d'une copie, suppression.
        $archive = 'INBOX.Archive';
        if (! collect($server->folders())->contains('path', $archive)) {
            $server->createFolder($archive);
        }
        $server->move('INBOX', [$uid], $archive);
        $this->assertSame([], $server->uids('INBOX', ['text' => $marker]));
        $moved = $server->uids($archive, ['text' => $marker]);
        $this->assertCount(1, $moved);

        $server->append($archive, "Subject: Copie {$marker}\r\nFrom: ".self::ADDRESS."\r\n\r\nCorps", ['\\Seen']);
        $this->assertCount(2, $server->uids($archive, ['text' => $marker]));

        $server->delete($archive, $server->uids($archive, ['text' => $marker]));
        $this->assertSame([], $server->uids($archive, ['text' => $marker]));

        $folders = collect($server->folders());
        $this->assertTrue($folders->contains('path', 'INBOX'));
        $this->assertTrue($folders->contains('name', 'Archive'));

        $server->disconnect();
    }

    /**
     * Le lot annoncé (plan) : compteurs, sélection, recherche, lecture et marquage
     * « lu » partent ensemble, et chaque lecture qui suit se sert de ce qui est arrivé.
     */
    public function test_an_announced_read_leaves_with_the_folder_counters(): void
    {
        $marker = 'rivo'.bin2hex(random_bytes(4));
        $sender = ImapMailServer::connect(self::ADDRESS, self::PASSWORD);
        $sender->send(OutgoingMessage::build(self::ADDRESS, 'Soa Rakoto', ['to' => self::ADDRESS, 'subject' => "Lot {$marker}", 'body_html' => "<p>Corps {$marker}</p>"], [], []));
        $uid = null;
        for ($attempt = 0; $attempt < 20 && $uid === null; $attempt++) {
            $uid = $sender->uids('INBOX', ['text' => $marker])[0] ?? null;
            if ($uid === null) {
                usleep(250_000);
            }
        }
        $sender->disconnect();
        $this->assertNotNull($uid);

        // Une liste : la recherche arrive avec les compteurs, et reste juste.
        $server = ImapMailServer::connect(self::ADDRESS, self::PASSWORD);
        $server->folders(false);
        $server->plan('INBOX', ['text' => $marker]);
        $inbox = collect($server->folders())->firstWhere('path', 'INBOX');
        $this->assertGreaterThanOrEqual(1, $inbox['unseen']);
        $listing = $server->messages('INBOX', 1, 25, ['text' => $marker]);
        $this->assertSame([$uid], array_column($listing['items'], 'uid'));
        $this->assertFalse($listing['items'][0]['seen']);
        $server->disconnect();

        // Un message : lu, sa position, et marqué lu — les drapeaux reçus sont ceux d'avant.
        $server = ImapMailServer::connect(self::ADDRESS, self::PASSWORD);
        $server->folders(false);
        $server->plan('INBOX', [], $uid, markSeen: true);
        $server->folders();
        $message = $server->message('INBOX', $uid);
        $this->assertSame("Lot {$marker}", $message['subject']);
        $this->assertStringContainsString("Corps {$marker}", (string) $message['html']);
        $this->assertFalse($message['seen'], 'lu avant le marquage : la messagerie sait qu’il était non lu');
        $this->assertContains($uid, $server->uids('INBOX'));
        $server->flag('INBOX', [$uid], '\\Seen', true);
        $server->disconnect();

        $check = ImapMailServer::connect(self::ADDRESS, self::PASSWORD);
        $this->assertTrue($check->messages('INBOX', 1, 25, ['text' => $marker])['items'][0]['seen'], 'le marquage du lot a bien eu lieu');
        $check->delete('INBOX', [$uid]);
        $check->disconnect();
    }
}
