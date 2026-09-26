<?php

namespace Tests\Feature\Webmail;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\ProfessionalMailbox;
use App\Models\Role;
use App\Models\User;
use App\Models\WebmailLabel;
use App\Models\WebmailTemplate;
use App\Services\Webmail\MailServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\Webmail\FakeMailServer;
use Tests\Support\Webmail\FakeMailServerFactory;
use Tests\TestCase;

/**
 * ADR-195 — la messagerie : la boîte pro du titulaire, et de lui seul ; le mot de
 * passe dans la session, jamais en base ; les messages lus en direct, jamais
 * copiés ; un HTML reçu rendu sûr ; l'envoi audité.
 */
class WebmailTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'soa.rakoto@cbdc.mg';

    private const PASSWORD = 'secret-boite';

    private FakeMailServerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
        $this->factory = new FakeMailServerFactory;
        $this->app->instance(MailServerFactory::class, $this->factory);
    }

    public function test_the_messagerie_depends_on_permissions_and_says_why_nothing_opens(): void
    {
        // Sans permission : ni menu, ni boîte — et la page nomme le droit qui manque.
        $stranger = User::factory()->withRole()->create();
        $this->actingAs($stranger)->get('/messagerie/connexion')->assertForbidden()->assertInertia(fn ($page) => $page
            ->component('Webmail/Unavailable')
            ->where('reason', 'permission')
            ->where('permission', 'webmail.view'));
        $this->actingAs($stranger)->get('/')->assertInertia(fn ($page) => $page->where('webmail.available', false));

        // Avec le droit, mais sans fiche employé : le menu apparaît, la page dit quoi faire.
        $stranger = $this->allow($stranger, 'webmail.view');
        $this->app->forgetScopedInstances();
        $this->actingAs($stranger)->get('/')->assertInertia(fn ($page) => $page->where('webmail.available', true));
        $this->actingAs($stranger)->get('/messagerie/connexion')->assertForbidden()->assertInertia(fn ($page) => $page
            ->component('Webmail/Unavailable')
            ->where('reason', 'unlinked'));

        [$user, $mailbox] = $this->titular();
        $this->actingAs($user)->get('/messagerie')->assertRedirect('/messagerie/connexion');
        $this->actingAs($user)->get('/messagerie/connexion')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Webmail/Connect')
            ->where('own.address', self::ADDRESS)
            ->where('own.owner', 'Soa Rakoto')
            ->where('own.own', true)
            ->where('canOpenAny', false)
            ->has('others', 0)
            ->where('webmail.available', true)
            ->where('webmail.connected', false));

        // Une adresse suspendue ferme la messagerie, et la page le dit.
        $mailbox->update(['status' => ProfessionalMailboxStatus::Suspended]);
        $this->app->forgetScopedInstances(); // une vraie requête repart de zéro
        $this->actingAs($user)->get('/messagerie/connexion')->assertForbidden()->assertInertia(fn ($page) => $page
            ->where('reason', 'inactive')
            ->where('status', 'Suspendue'));
    }

    public function test_a_denied_webmail_view_closes_even_the_titulars_own_box(): void
    {
        [$user] = $this->titular();
        $permission = Permission::query()->where('name', 'webmail.view')->value('id');
        $user->permissions()->syncWithoutDetaching([$permission => ['effect' => 'deny']]);

        $this->actingAs($user->fresh())->get('/messagerie/connexion')->assertForbidden()->assertInertia(fn ($page) => $page->where('reason', 'permission'));
    }

    public function test_the_password_is_checked_by_the_mail_server_and_kept_encrypted_in_the_session_only(): void
    {
        [$user] = $this->titular();

        $this->actingAs($user)->post('/messagerie/connexion', ['password' => 'mauvais'])
            ->assertSessionHasErrors(['password' => 'Le serveur de messagerie refuse ce mot de passe.']);
        $this->assertTrue(AuditLog::query()->where('action', 'webmail.connect_failed')->exists());

        $this->actingAs($user)->post('/messagerie/connexion', ['password' => self::PASSWORD])->assertRedirect(route('webmail.index'));
        $this->assertTrue(AuditLog::query()->where('action', 'webmail.connect')->exists());

        $stored = session('webmail.credentials');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored, 'jamais en clair, même dans la session');
        $this->assertStringNotContainsString(self::PASSWORD, AuditLog::query()->get()->toJson(), 'jamais dans l’audit');

        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertOk();

        $this->actingAs($user)->post('/messagerie/deconnexion')->assertRedirect('/messagerie/connexion');
        $this->assertNull(session('webmail.credentials'));
        $this->assertTrue(AuditLog::query()->where('action', 'webmail.disconnect')->exists());
    }

    public function test_a_changed_password_is_forgotten_and_asked_again(): void
    {
        [$user] = $this->connected();
        $this->factory->changePassword(self::ADDRESS, 'nouveau');

        $this->actingAs($user)->get('/messagerie/dossier/reception')
            ->assertRedirect('/messagerie/connexion')
            ->assertSessionHasErrors('password');
        $this->assertNull(session('webmail.credentials'));
    }

    public function test_an_unreachable_server_is_said_not_thrown(): void
    {
        [$user] = $this->connected();
        $this->factory->offline = true;

        $this->actingAs($user)->get('/messagerie/dossier/reception')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Webmail/Offline')->where('message', 'Le serveur de messagerie ne répond pas. Réessayez dans un instant.'));
    }

    public function test_the_folder_lists_newest_first_with_french_folders_counts_and_the_frame(): void
    {
        [$user, , $box] = $this->connected();
        $old = $box->seed('INBOX', ['subject' => 'Ancien', 'flags' => ['\\Seen']]);
        $new = $box->seed('INBOX', ['subject' => 'Nouveau', 'from' => ['name' => 'Dr Vola', 'email' => 'vola@exemple.mg']]);
        $box->createFolder('INBOX.Factures');

        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Webmail/Index')
            ->where('current', 'reception')
            ->where('list.total', 2)
            ->where('list.items.0.uid', $new)
            ->where('list.items.0.seen', false)
            ->where('list.items.0.folder', 'reception')
            ->where('list.items.1.uid', $old)
            ->where('folders.0.name', 'Boîte de réception')
            ->where('folders.0.unseen', 1)
            ->where('folders.2.key', 'favoris')
            ->where('folders.3.name', 'Envoyés')
            ->where('folders.4.name', 'Archives')
            ->where('folders.7.name', 'Factures')
            ->where('folders.7.custom', true)
            ->missing('folders.0.path')
            ->where('mailbox.address', self::ADDRESS)
            ->where('quota.limit', 1024 * 1024 * 1024)
            ->where('message', null));
    }

    public function test_search_and_the_unread_filter_are_asked_to_the_server(): void
    {
        [$user, , $box] = $this->connected();
        $box->seed('INBOX', ['subject' => 'Compte rendu échographie', 'flags' => ['\\Seen']]);
        $box->seed('INBOX', ['subject' => 'Réunion lundi']);

        $this->actingAs($user)->get('/messagerie/dossier/reception?q=échographie')
            ->assertInertia(fn ($page) => $page->where('list.total', 1)->where('list.items.0.subject', 'Compte rendu échographie')->where('filters.q', 'échographie'));
        $this->actingAs($user)->get('/messagerie/dossier/reception?filtre=non-lus')
            ->assertInertia(fn ($page) => $page->where('list.total', 1)->where('list.items.0.subject', 'Réunion lundi'));
        $this->actingAs($user)->get('/messagerie/dossier/inconnu')->assertNotFound();
    }

    public function test_opening_a_message_marks_it_read_and_renders_its_html_safely(): void
    {
        [$user, , $box] = $this->connected();
        $previous = $box->seed('INBOX', ['subject' => 'Avant']);
        $uid = $box->seed('INBOX', [
            'subject' => 'Résultats',
            'html' => '<p onclick="alert(1)">Bonjour</p><script>alert(2)</script><a href="javascript:alert(3)">x</a>'
                .'<img src="https://pistage.exemple/p.gif"><img src="cid:logo@x"><form action="/"><input></form>',
            'attachments' => [
                ['part' => '2', 'name' => 'resultats.pdf', 'type' => 'application/pdf', 'content' => '%PDF-1.4'],
                ['part' => '3', 'name' => 'logo.png', 'type' => 'image/png', 'content' => 'PNG', 'cid' => 'logo@x', 'inline' => true],
            ],
        ]);

        $this->actingAs($user)->get("/messagerie/dossier/reception/{$uid}")->assertOk()->assertInertia(function ($page) use ($previous) {
            $page->component('Webmail/Index')
                ->where('message.subject', 'Résultats')
                ->where('message.seen', true)
                ->where('message.blocked_images', 1)
                ->where('message.remote_images', false)
                ->where('message.attachments.0.name', 'resultats.pdf')
                ->has('message.attachments', 1)
                ->where('message.older', $previous)
                ->where('list', null);

            $html = $page->toArray()['props']['message']['body_html'];
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('pistage.exemple', $html);
            $this->assertStringNotContainsString('<form', $html);
            $this->assertStringContainsString('data:image/png;base64,', $html, 'l’image incorporée est affichée');
        });

        $this->assertContains('\\Seen', $box->stored('INBOX', $uid)['flags']);

        // Les images distantes s'affichent sur demande.
        $this->actingAs($user)->get("/messagerie/dossier/reception/{$uid}?images=1")
            ->assertInertia(fn ($page) => $page->where('message.blocked_images', 0)->where('message.remote_images', true));
    }

    public function test_an_attachment_is_always_downloaded_never_rendered(): void
    {
        [$user, , $box] = $this->connected();
        $uid = $box->seed('INBOX', ['attachments' => [['part' => '2', 'name' => 'page.html', 'type' => 'text/html', 'content' => '<script>alert(1)</script>']]]);

        $this->actingAs($user)->get("/messagerie/dossier/reception/{$uid}/pieces/2")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename="page.html"; filename*=UTF-8\'\'page.html');

        $this->actingAs($user)->get("/messagerie/dossier/reception/{$uid}/pieces/9")->assertNotFound();
    }

    public function test_actions_star_archive_trash_and_delete_only_from_the_trash(): void
    {
        [$user, , $box] = $this->connected();
        $a = $box->seed('INBOX', ['subject' => 'A']);
        $b = $box->seed('INBOX', ['subject' => 'B']);

        $this->act($user, [['folder' => 'reception', 'uid' => $a]], 'star')->assertSessionHas('status', '1 message ajouté aux favoris.');
        $this->assertContains('\\Flagged', $box->stored('INBOX', $a)['flags']);
        $this->actingAs($user)->get('/messagerie/dossier/favoris')->assertInertia(fn ($page) => $page->where('list.total', 1)->where('list.items.0.folder', 'reception'));

        // Archives n'existe pas encore : créée à côté des autres, sous « INBOX. ».
        $this->act($user, [['folder' => 'reception', 'uid' => $a]], 'archive')->assertSessionHas('status', '1 message archivé.');
        $this->assertArrayHasKey('INBOX.Archive', $box->folders);
        $this->assertCount(1, $box->folders['INBOX.Archive']['messages']);

        $this->act($user, [['folder' => 'reception', 'uid' => $b]], 'delete')->assertSessionHasErrors('action');
        $this->act($user, [['folder' => 'reception', 'uid' => $b]], 'trash')->assertSessionHas('status', '1 message mis à la corbeille.');
        $trashed = array_key_first($box->folders['INBOX.Trash']['messages']);

        $this->act($user, [['folder' => 'corbeille', 'uid' => $trashed]], 'delete')->assertSessionHas('status', '1 message supprimé définitivement.');
        $this->assertCount(0, $box->folders['INBOX.Trash']['messages']);
        $this->assertTrue(AuditLog::query()->where('action', 'webmail.delete')->exists());
    }

    public function test_an_action_that_removes_the_open_message_returns_to_its_folder_never_elsewhere(): void
    {
        [$user, , $box] = $this->connected();
        $uid = $box->seed('INBOX');
        $other = $box->seed('INBOX');

        $this->actingAs($user)->from("/messagerie/dossier/reception/{$uid}")
            ->post('/messagerie/actions', ['items' => [['folder' => 'reception', 'uid' => $uid]], 'action' => 'trash', 'return_to' => '/messagerie/dossier/reception?page=2'])
            ->assertRedirect('/messagerie/dossier/reception?page=2');

        foreach (['https://exemple.mg/', '//exemple.mg/x', '/admin', '/messagerie/../logout'] as $outside) {
            $this->actingAs($user)->from("/messagerie/dossier/reception/{$other}")
                ->post('/messagerie/actions', ['items' => [['folder' => 'reception', 'uid' => $other]], 'action' => 'read', 'return_to' => $outside])
                ->assertRedirect("/messagerie/dossier/reception/{$other}");
        }
    }

    public function test_labels_are_the_accounts_own_and_set_as_imap_keywords(): void
    {
        [$user, , $box] = $this->connected();
        $uid = $box->seed('INBOX');

        $this->actingAs($user)->post('/messagerie/libelles', ['name' => 'Laboratoire', 'color' => 'green'])->assertSessionHasNoErrors();
        $label = WebmailLabel::sole();
        $this->assertMatchesRegularExpression('/^rivo[a-z0-9]{10}$/', $label->keyword);
        $this->actingAs($user)->post('/messagerie/libelles', ['name' => 'Laboratoire', 'color' => 'red'])->assertSessionHasErrors('name');

        $this->act($user, [['folder' => 'reception', 'uid' => $uid]], 'label', ['label' => $label->uuid])->assertSessionHas('status', 'Libellé « Laboratoire » ajouté.');
        $this->assertContains($label->keyword, $box->stored('INBOX', $uid)['flags']);
        $this->actingAs($user)->get('/messagerie/dossier/reception?libelle='.$label->uuid)->assertInertia(fn ($page) => $page->where('list.total', 1));

        // Le libellé d'un autre compte n'existe pas pour celui-ci.
        [$other] = $this->titular('vola.rabe@cbdc.mg', 'Vola', 'Rabe');
        $this->actingAs($other)->put("/messagerie/libelles/{$label->uuid}", ['name' => 'Pris', 'color' => 'red'])->assertNotFound();

        $this->actingAs($user)->put("/messagerie/libelles/{$label->uuid}", ['name' => 'Labo', 'color' => 'violet'])->assertSessionHasNoErrors();
        $this->assertSame('violet', $label->refresh()->color);
        $this->actingAs($user)->delete("/messagerie/libelles/{$label->uuid}")->assertSessionHasNoErrors();
        $this->assertSame(0, WebmailLabel::query()->count());
    }

    public function test_sending_goes_from_the_titular_is_kept_in_sent_and_audited(): void
    {
        [$user, , $box] = $this->connected();

        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'pas-une-adresse', 'subject' => 'X', 'body_html' => '<p>x</p>'])
            ->assertSessionHasErrors(['to' => 'Adresse invalide : pas-une-adresse.']);
        $this->actingAs($user)->post('/messagerie/envoyer', ['subject' => 'X', 'body_html' => '<p>x</p>'])
            ->assertSessionHasErrors(['to' => 'Indiquez au moins un destinataire.']);
        $this->assertCount(0, $box->sent);

        $this->actingAs($user)->post('/messagerie/envoyer', [
            'to' => 'Dr Vola <vola@exemple.mg>; labo@exemple.mg',
            'bcc' => 'direction@cbdc.mg',
            'subject' => 'Résultats de Mme R.',
            'body_html' => '<p>Bonjour <strong>docteur</strong></p><script>alert(1)</script><img src="x" onerror="alert(2)">',
            'attachments' => [UploadedFile::fake()->createWithContent('bilan.pdf', '%PDF-1.4')],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Message envoyé.');

        $email = $box->sent[0];
        $this->assertSame(self::ADDRESS, $email->getFrom()[0]->getAddress());
        $this->assertSame('Soa Rakoto', $email->getFrom()[0]->getName());
        $this->assertSame(['vola@exemple.mg', 'labo@exemple.mg'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertStringContainsString('<strong>docteur</strong>', $email->getHtmlBody());
        $this->assertStringNotContainsString('script', $email->getHtmlBody());
        $this->assertStringNotContainsString('onerror', $email->getHtmlBody());
        $this->assertSame('Bonjour docteur', $email->getTextBody());
        $this->assertCount(1, $email->getAttachments());

        // Sa copie dans Envoyés garde la copie cachée, que le message parti ne porte pas.
        $copy = array_values($box->folders['INBOX.Sent']['messages'])[0];
        $this->assertSame('Résultats de Mme R.', $copy['subject']);
        $this->assertContains('\\Seen', $copy['flags']);
        $this->assertStringStartsWith('Bcc: direction@cbdc.mg', $copy['raw']);

        $audit = AuditLog::query()->where('action', 'webmail.send')->sole();
        $this->assertSame(['vola@exemple.mg', 'labo@exemple.mg'], $audit->new_values['to']);
        $this->assertSame(1, $audit->new_values['attachments']);
        $this->assertArrayNotHasKey('body_html', $audit->new_values, 'le corps n’est jamais audité');
    }

    public function test_a_reply_threads_marks_the_original_answered_and_a_failed_send_changes_nothing(): void
    {
        [$user, , $box] = $this->connected();
        $original = $box->seed('INBOX', ['message_id' => 'orig@exemple.mg', 'references' => '<avant@exemple.mg>']);

        $this->actingAs($user)->post('/messagerie/envoyer', [
            'to' => 'expediteur@exemple.mg', 'subject' => 'Re: Sujet', 'body_html' => '<p>Merci</p>',
            'reply' => ['folder' => 'reception', 'uid' => $original],
        ])->assertSessionHasNoErrors();

        $headers = $box->sent[0]->getHeaders();
        $this->assertSame(['orig@exemple.mg'], $headers->get('In-Reply-To')->getIds());
        $this->assertSame('<avant@exemple.mg> <orig@exemple.mg>', $headers->get('References')->getBodyAsString());
        $this->assertContains('\\Answered', $box->stored('INBOX', $original)['flags']);

        $box->failSend = true;
        $before = count($box->folders['INBOX.Sent']['messages']);
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'a@exemple.mg', 'subject' => 'X', 'body_html' => '<p>x</p>'])
            ->assertSessionHasErrors('webmail');
        $this->assertCount($before, $box->folders['INBOX.Sent']['messages'], 'rien dans Envoyés pour un message qui n’est pas parti');
    }

    public function test_a_draft_is_saved_then_replaced_then_removed_when_sent(): void
    {
        [$user, , $box] = $this->connected();

        $this->actingAs($user)->post('/messagerie/brouillons', ['to' => '', 'subject' => 'Brouillon', 'body_html' => '<p>À finir</p>'])
            ->assertSessionHas('status', 'Brouillon enregistré dans « Brouillons ».');
        $first = array_key_first($box->folders['INBOX.Drafts']['messages']);
        $this->assertContains('\\Draft', $box->stored('INBOX.Drafts', $first)['flags']);

        $this->actingAs($user)->post('/messagerie/brouillons', ['subject' => 'Brouillon v2', 'body_html' => '<p>Presque</p>', 'draft' => ['folder' => 'brouillons', 'uid' => $first]]);
        $this->assertCount(1, $box->folders['INBOX.Drafts']['messages'], 'la nouvelle version remplace l’ancienne');
        $second = array_key_first($box->folders['INBOX.Drafts']['messages']);

        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'a@exemple.mg', 'subject' => 'Brouillon v2', 'body_html' => '<p>Fini</p>', 'draft' => ['folder' => 'brouillons', 'uid' => $second]])
            ->assertSessionHasNoErrors();
        $this->assertCount(0, $box->folders['INBOX.Drafts']['messages']);
    }

    public function test_forwarding_carries_the_original_attachments(): void
    {
        [$user, , $box] = $this->connected();
        $uid = $box->seed('INBOX', ['attachments' => [['part' => '2', 'name' => 'ecg.pdf', 'type' => 'application/pdf', 'content' => '%PDF-ECG']]]);

        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'b@exemple.mg', 'subject' => 'Tr: ECG', 'body_html' => '<p>Pour avis</p>', 'forward' => ['folder' => 'reception', 'uid' => $uid]])
            ->assertSessionHasNoErrors();

        $attachments = $box->sent[0]->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('ecg.pdf', $attachments[0]->getFilename());
    }

    public function test_templates_are_the_accounts_own_and_their_body_is_cleaned(): void
    {
        [$user] = $this->connected();

        $this->actingAs($user)->post('/messagerie/modeles', ['name' => 'Accusé de réception', 'subject' => 'Bien reçu', 'body_html' => '<p>Bien reçu, merci.</p><script>x()</script>'])
            ->assertSessionHasNoErrors();
        $template = WebmailTemplate::sole();
        $this->assertSame('<p>Bien reçu, merci.</p>', $template->body_html);

        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertInertia(fn ($page) => $page->where('templates.0.name', 'Accusé de réception'));
        $this->actingAs($user)->post('/messagerie/modeles', ['name' => 'Vide', 'body_html' => '<p> </p>'])->assertSessionHasErrors('body_html');
        $this->actingAs($user)->delete("/messagerie/modeles/{$template->uuid}")->assertSessionHasNoErrors();
        $this->assertSame(0, WebmailTemplate::query()->count());
    }

    public function test_colleagues_with_an_active_address_are_offered_as_contacts(): void
    {
        [$user] = $this->connected();
        $this->titular('vola.rabe@cbdc.mg', 'Vola', 'Rabe');

        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertInertia(fn ($page) => $page
            ->has('contacts', 1)
            ->where('contacts.0.email', 'vola.rabe@cbdc.mg')
            ->where('contacts.0.name', 'Vola Rabe'));
    }

    public function test_the_mail_server_is_reached_only_when_the_page_needs_it(): void
    {
        [$user, , $box] = $this->connected();
        $box->seed('INBOX', ['subject' => 'Bonjour']);
        $partial = fn (string $only) => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) \Inertia\Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Webmail/Index', 'X-Inertia-Partial-Data' => $only];

        // Une page entière se connecte une fois.
        $this->factory->connections = 0;
        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertOk();
        $this->assertSame(1, $this->factory->connections);

        // Un libellé renommé ne recharge que les libellés : aucune connexion.
        $this->factory->connections = 0;
        $this->actingAs($user)->get('/messagerie/dossier/reception', $partial('labels,flash,errors'))->assertOk()->assertJsonPath('props.labels', []);
        $this->assertSame(0, $this->factory->connections, 'aucune connexion pour ce qui ne vient pas du serveur');

        // Les compteurs seulement : une connexion, sans relire la liste.
        $this->factory->connections = 0;
        $response = $this->actingAs($user)->get('/messagerie/dossier/reception', $partial('folders'))->assertOk();
        $this->assertSame(1, $this->factory->connections);
        $this->assertArrayNotHasKey('list', $response->json('props'));
    }

    public function test_the_page_announces_what_it_reads_so_it_leaves_with_the_folder_counters(): void
    {
        [$user, , $box] = $this->connected();
        $uid = $box->seed('INBOX', ['subject' => 'Bonjour']);
        $draft = $box->seed('INBOX.Drafts', ['subject' => 'Brouillon', 'flags' => ['\\Draft']]);
        $partial = fn (string $only) => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) \Inertia\Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Webmail/Index', 'X-Inertia-Partial-Data' => $only];

        // La liste : sa recherche part avec les compteurs, critères compris.
        $box->plans = [];
        $this->actingAs($user)->get('/messagerie/dossier/reception?q=bonjour&filtre=non-lus')->assertOk();
        $this->assertCount(1, $box->plans);
        $this->assertSame('INBOX', $box->plans[0]['folder']);
        $this->assertSame('bonjour', $box->plans[0]['criteria']['text']);
        $this->assertTrue($box->plans[0]['criteria']['unseen']);
        $this->assertNull($box->plans[0]['uid']);

        // Les filtres comptent le dossier entier : tous, non lus, favoris.
        $box->seed('INBOX', ['subject' => 'Lu', 'flags' => ['\\Seen']]);
        $box->seed('INBOX', ['subject' => 'Favori', 'flags' => ['\\Flagged']]);
        $this->actingAs($user)->get('/messagerie/dossier/reception?q=favori')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('list.counts.all', 3)
                ->where('list.counts.unseen', 2)
                ->where('list.counts.flagged', 1)
                ->where('list.total', 1));

        // Un rechargement partiel qui ne demande pas la liste n'annonce rien.
        $box->plans = [];
        $this->actingAs($user)->get('/messagerie/dossier/reception', $partial('folders'))->assertOk();
        $this->assertSame([], $box->plans);

        // Un message : sa lecture et son marquage « lu » partent avec les compteurs.
        $box->plans = [];
        $this->actingAs($user)->get("/messagerie/dossier/reception/{$uid}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('message.seen', true)->where('folders.0.unseen', 1)); // 2 non lus, un de moins
        $this->assertSame(['folder' => 'INBOX', 'criteria' => [], 'uid' => $uid, 'markSeen' => true], $box->plans[0]);
        $this->assertContains('\\Seen', $box->stored('INBOX', $uid)['flags']);

        // Un brouillon s'ouvre sans être marqué lu.
        $box->plans = [];
        $this->actingAs($user)->get("/messagerie/dossier/brouillons/{$draft}")->assertOk();
        $this->assertFalse($box->plans[0]['markSeen']);
    }

    public function test_a_background_send_answers_in_json_and_a_refused_one_keeps_the_message(): void
    {
        [$user, , $box] = $this->connected();
        $json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        // Parti : une réponse JSON, pas une page — et l'audit, comme toujours.
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'vola@exemple.mg', 'subject' => 'En arrière-plan', 'body_html' => '<p>x</p>'], $json)
            ->assertOk()->assertExactJson(['status' => 'Message envoyé.']);
        $this->assertCount(1, $box->sent);
        $this->assertSame(1, AuditLog::query()->where('action', 'webmail.send')->count());

        // Refusé par la validation : les erreurs, en JSON, pour la fenêtre qui se rouvre.
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'pas-une-adresse', 'subject' => 'X', 'body_html' => '<p>x</p>'], $json)
            ->assertUnprocessable()->assertJsonPath('errors.to.0', 'Adresse invalide : pas-une-adresse.');

        // Le serveur d'envoi ne répond pas : la raison, rien dans Envoyés.
        $box->failSend = true;
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'a@exemple.mg', 'subject' => 'X', 'body_html' => '<p>x</p>'], $json)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['webmail']]);
        $this->assertCount(1, $box->sent);

        // Le mot de passe a changé : la boîte se referme, le message reste à l'écran.
        $box->failSend = false;
        $this->factory->changePassword(self::ADDRESS, 'nouveau');
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'a@exemple.mg', 'subject' => 'X', 'body_html' => '<p>x</p>'], $json)
            ->assertStatus(409)->assertJsonPath('reconnect', route('webmail.connect'));

        // Boîte refermée : même réponse, jamais une page de connexion prise pour un succès.
        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'a@exemple.mg', 'subject' => 'X', 'body_html' => '<p>x</p>'], $json)
            ->assertStatus(409)->assertJsonPath('reconnect', route('webmail.connect'));
    }

    public function test_the_copy_kept_in_sent_is_the_message_that_left(): void
    {
        [$user, , $box] = $this->connected();

        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'dr.vola@exemple.mg', 'subject' => 'Compte rendu', 'body_html' => '<p>Bonjour</p>'])
            ->assertSessionHasNoErrors();

        $sent = $box->sent[0];
        $messageId = $sent->getHeaders()->get('Message-ID')?->getBodyAsString();
        $this->assertNotEmpty($messageId);

        $copy = collect($box->folders['INBOX.Sent']['messages'])->first()['raw'] ?? null;
        $this->assertStringContainsString($messageId, (string) $copy, 'même identifiant : une réponse se rattache au bon fil');
    }

    public function test_another_employees_box_opens_only_with_open_any_and_says_so_everywhere(): void
    {
        [$user] = $this->titular();
        [, $other] = $this->titular('vola.rabe@cbdc.mg', 'Vola', 'Rabe');

        // Sans `webmail.open_any`, la boîte d'un autre ne s'ouvre pas, même en connaissant son identifiant.
        $this->actingAs($user)->post('/messagerie/connexion', ['mailbox' => $other->uuid, 'password' => self::PASSWORD])->assertForbidden();
        $this->assertNull(session('webmail.credentials'));

        $user = $this->allow($user, 'webmail.open_any');
        $this->app->forgetScopedInstances();
        $this->actingAs($user)->get('/messagerie/connexion')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canOpenAny', true)
            ->where('own.address', self::ADDRESS)
            ->has('others', 1)
            ->where('others.0.address', 'vola.rabe@cbdc.mg')
            ->where('others.0.own', false));

        $this->actingAs($user)->post('/messagerie/connexion', ['mailbox' => $other->uuid, 'password' => self::PASSWORD])
            ->assertRedirect(route('webmail.index'));
        $connect = AuditLog::query()->where('action', 'webmail.connect')->latest('id')->first();
        $this->assertSame('vola.rabe@cbdc.mg', $connect->new_values['address']);
        $this->assertFalse($connect->new_values['own_mailbox']);
        $this->assertSame('Vola Rabe', $connect->new_values['titular']);

        $this->app->forgetScopedInstances();
        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertOk()->assertInertia(fn ($page) => $page
            ->where('mailbox.address', 'vola.rabe@cbdc.mg')
            ->where('mailbox.owner', 'Vola Rabe')
            ->where('mailbox.own', false)
            ->where('mailbox.can_switch', true)
            ->where('contacts.0.email', self::ADDRESS));

        $this->actingAs($user)->post('/messagerie/envoyer', ['to' => 'dr.vola@exemple.mg', 'subject' => 'Rendez-vous', 'body_html' => '<p>Bonjour</p>'])
            ->assertSessionHasNoErrors();
        $send = AuditLog::query()->where('action', 'webmail.send')->latest('id')->first();
        $this->assertSame('vola.rabe@cbdc.mg', $send->new_values['from']);
        $this->assertFalse($send->new_values['own_mailbox']);

        // Suspendue, elle se referme : RIVO revient à la boîte du compte, dont il faut le mot de passe.
        $other->update(['status' => ProfessionalMailboxStatus::Suspended]);
        $this->app->forgetScopedInstances();
        $this->actingAs($user)->get('/messagerie/dossier/reception')->assertRedirect('/messagerie/connexion');
        $this->actingAs($user)->post('/messagerie/connexion', ['mailbox' => $other->uuid, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['mailbox' => 'Cette boîte n’est pas (ou plus) active.']);
    }

    public function test_the_super_admin_opens_any_sites_box_from_the_portal_through_its_api(): void
    {
        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.site_api.retry_times' => 1,
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $admin = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
        $uuid = '9d2f6a3e-8c1b-4f7a-9e2d-5b6c7d8e9f01';
        Http::fake(['https://a.test/api/v1/super-admin/professional-mailboxes' => Http::response([
            'data' => [
                ['uuid' => $uuid, 'address' => 'vola.rabe@cbdc.mg', 'status' => 'ACTIVE', 'employee' => ['name' => 'Vola Rabe', 'job_title' => 'Sage-femme']],
                ['uuid' => '1d2f6a3e-8c1b-4f7a-9e2d-5b6c7d8e9f02', 'address' => 'parti@cbdc.mg', 'status' => 'SUSPENDED', 'employee' => ['name' => 'Parti']],
            ],
            'meta' => [],
        ])]);
        $this->factory->box('vola.rabe@cbdc.mg', self::PASSWORD);

        $this->actingAs($admin)->get('/messagerie')->assertRedirect('/messagerie/connexion');
        $this->actingAs($admin)->get('/messagerie/connexion')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Webmail/Connect')
            ->where('portal', true)
            ->where('own', null)
            ->has('others', 1)
            ->where('others.0.address', 'vola.rabe@cbdc.mg')
            ->where('others.0.site_name', 'Ambondromamy')
            ->where('webmail.available', true));

        // Une boîte que le site ne liste pas ne s'ouvre pas, quoi qu'envoie le navigateur.
        $this->actingAs($admin)->post('/messagerie/connexion', ['mailbox' => '1d2f6a3e-8c1b-4f7a-9e2d-5b6c7d8e9f02', 'site' => 'A', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('mailbox');

        $this->actingAs($admin)->post('/messagerie/connexion', ['mailbox' => $uuid, 'site' => 'A', 'password' => self::PASSWORD])
            ->assertRedirect(route('webmail.index'));
        $connect = AuditLog::query()->where('action', 'webmail.connect')->latest('id')->first();
        $this->assertSame('A', $connect->new_values['site']);
        $this->assertFalse($connect->new_values['own_mailbox']);

        $this->app->forgetScopedInstances();
        $this->actingAs($admin)->get('/messagerie/dossier/reception')->assertOk()->assertInertia(fn ($page) => $page
            ->where('mailbox.address', 'vola.rabe@cbdc.mg')
            ->where('mailbox.site_name', 'Ambondromamy')
            ->where('mailbox.own', false));
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: User, 1: ProfessionalMailbox} */
    private function titular(string $address = self::ADDRESS, string $first = 'Soa', string $last = 'Rakoto'): array
    {
        $user = $this->allow(User::factory()->withRole()->create(), 'webmail.view');
        $employee = Employee::query()->create([
            'employee_number' => fake()->unique()->bothify('EMP-####'),
            'user_id' => $user->id,
            'first_name' => $first,
            'last_name' => $last,
            'sex' => 'F',
            'active' => true,
        ]);
        $mailbox = ProfessionalMailbox::query()->create([
            'employee_id' => $employee->id,
            'address' => $address,
            'status' => ProfessionalMailboxStatus::Active,
            'requested_at' => now(),
            'activated_at' => now(),
        ]);
        $this->factory->box($address, self::PASSWORD);

        return [$user, $mailbox];
    }

    private function allow(User $user, string $permission): User
    {
        $user->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', $permission)->value('id') => ['effect' => 'allow'],
        ]);

        return $user->fresh();
    }

    /** @return array{0: User, 1: ProfessionalMailbox, 2: FakeMailServer} */
    private function connected(): array
    {
        [$user, $mailbox] = $this->titular();
        $this->actingAs($user)->post('/messagerie/connexion', ['password' => self::PASSWORD])->assertSessionHasNoErrors();

        return [$user, $mailbox, $this->factory->box(self::ADDRESS)];
    }

    /** @param list<array{folder: string, uid: int}> $items */
    private function act(User $user, array $items, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->from('/messagerie/dossier/reception')->post('/messagerie/actions', ['items' => $items, 'action' => $action, ...$extra]);
    }
}
