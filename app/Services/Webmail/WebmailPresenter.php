<?php

namespace App\Services\Webmail;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\ProfessionalMailbox;
use App\Models\User;
use App\Models\WebmailLabel;
use App\Models\WebmailTemplate;

/**
 * ADR-195 — ce que chaque écran de la messagerie reçoit, en plus de sa liste ou
 * de son message : la boîte, ses dossiers et leurs compteurs, les libellés et les
 * modèles du compte, les collègues joignables et l'espace utilisé.
 */
final class WebmailPresenter
{
    public function __construct(private readonly WebmailAccess $access) {}

    /**
     * Chaque partie est calculée seulement si la page la demande : un rechargement
     * partiel (`only: ['labels']`) ne touche pas au serveur de messagerie.
     *
     * @return array<string, mixed>
     */
    public function frame(User $user, WebmailMailbox $box): array
    {
        $opened = $box->box();

        return [
            'mailbox' => [
                'address' => $box->address(),
                'owner' => $opened->owner,
                'site_name' => $opened->siteName,
                // La boîte d'un autre employé se dit à l'écran, en permanence.
                'own' => $opened->own,
                // La boîte du portail s'ouvre sans mot de passe saisi : rien à fermer.
                'portal' => $opened->portal,
                'can_switch' => $this->access->canOpenAny($user),
            ],
            'folders' => fn () => array_map(fn (array $folder) => collect($folder)->except('path')->all(), $box->folders()),
            'labels' => fn () => WebmailLabel::query()->where('user_id', $user->id)->orderBy('name')->get()
                ->map(fn (WebmailLabel $label) => [
                    'uuid' => $label->uuid,
                    'name' => $label->name,
                    'color' => $label->color,
                    'keyword' => $label->keyword,
                ])->all(),
            'labelColors' => WebmailLabel::COLORS,
            'templates' => fn () => WebmailTemplate::query()->where('user_id', $user->id)->orderBy('name')->get()
                ->map(fn (WebmailTemplate $template) => [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                ])->all(),
            'contacts' => fn () => $this->contacts($user, $opened),
            'quota' => fn () => $box->server()->quota(),
            'limits' => [
                'attachment_max_mb' => (int) config('rivo.webmail.attachment_max_mb', 10),
                'attachments_total_mb' => (int) config('rivo.webmail.attachments_total_mb', 20),
                'max_recipients' => OutgoingMessage::MAX_RECIPIENTS,
            ],
        ];
    }

    /**
     * Les collègues joignables d'un clic : les autres adresses professionnelles
     * actives du site — ou, sur le portail, de chaque site. Rien d'autre sur eux
     * que leur nom, leur fonction et l'adresse. Toute autre adresse se tape.
     *
     * @return list<array{name: string, job: ?string, email: string}>
     */
    private function contacts(User $user, WebmailBox $opened): array
    {
        $boxes = WebmailAccess::onPortal()
            ? $this->access->siteAddresses($user)
            : ProfessionalMailbox::query()
                ->with(['employee.jobTitle'])
                ->where('status', ProfessionalMailboxStatus::Active->value)
                ->get()
                ->map(fn (ProfessionalMailbox $mailbox) => WebmailBox::fromMailbox($mailbox, own: false))
                ->all();

        return collect($boxes)
            ->reject(fn (WebmailBox $box) => $box->address === $opened->address)
            ->map(fn (WebmailBox $box) => ['name' => $box->owner, 'job' => $box->job, 'email' => $box->address])
            ->unique('email')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
