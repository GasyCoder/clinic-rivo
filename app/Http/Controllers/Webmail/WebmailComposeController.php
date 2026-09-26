<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Services\Audit\Auditor;
use App\Services\Webmail\OutgoingMessage;
use App\Services\Webmail\WebmailAccess;
use App\Services\Webmail\WebmailMailbox;
use App\Services\Webmail\WebmailPresenter;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;
use App\Support\Webmail\WebmailReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;

/**
 * ADR-194 — envoyer un message, ou le garder en brouillon. Il part de l'adresse
 * du titulaire, par le serveur d'envoi de l'hébergeur ; sa copie va dans Envoyés.
 *
 * L'envoi est audité (destinataires, objet, nombre de pièces jointes) : un message
 * qui quitte la clinique peut porter des données de santé. Le corps ne l'est pas.
 */
class WebmailComposeController extends Controller
{
    /**
     * L'envoi part en arrière-plan (fetch, en JSON) : la fenêtre se ferme aussitôt, et
     * l'écran apprend ici si le message est parti. Refusé, il revient dans la fenêtre.
     */
    public function send(Request $request, WebmailMailbox $box, WebmailAccess $access, WebmailPresenter $presenter, Auditor $auditor): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request, requireRecipient: true);
        $email = $this->build($request, $data, $box, $access, $presenter);

        try {
            $box->send($email, $data['reply'] ?? null, $data['draft'] ?? null);
        } catch (WebmailAuthenticationFailed $exception) {
            throw $exception; // mot de passe refusé : redemandé (bootstrap/app.php)
        } catch (WebmailUnavailable $exception) {
            return self::inBackground($request)
                ? response()->json(['message' => $exception->getMessage(), 'errors' => ['webmail' => [$exception->getMessage()]]], 422)
                : back()->withErrors(['webmail' => $exception->getMessage()]);
        }

        $auditor->record('webmail.send', newValues: [
            'from' => $box->address(),
            'to' => array_map(fn ($address) => $address->getAddress(), $email->getTo()),
            'cc' => array_map(fn ($address) => $address->getAddress(), $email->getCc()),
            'bcc' => array_map(fn ($address) => $address->getAddress(), $email->getBcc()),
            'subject' => $email->getSubject(),
            'attachments' => count($email->getAttachments()),
            // Envoyé depuis la boîte d'un autre employé (`webmail.open_any`) : l'audit le dit.
            'titular' => $box->box()->owner,
            'own_mailbox' => $box->box()->own,
        ], module: 'webmail');

        return self::inBackground($request)
            ? response()->json(['status' => 'Message envoyé.'])
            : WebmailReturn::redirect($request)->with('status', 'Message envoyé.');
    }

    /** Une requête d'arrière-plan (fetch) attend du JSON, pas une page. */
    private static function inBackground(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    public function draft(Request $request, WebmailMailbox $box, WebmailAccess $access, WebmailPresenter $presenter): RedirectResponse
    {
        $data = $this->validated($request, requireRecipient: false);

        try {
            $box->saveDraft($this->build($request, $data, $box, $access, $presenter), $data['draft'] ?? null);
        } catch (WebmailAuthenticationFailed $exception) {
            throw $exception; // mot de passe refusé : redemandé (bootstrap/app.php)
        } catch (WebmailUnavailable $exception) {
            return back()->withErrors(['webmail' => $exception->getMessage()]);
        }

        return WebmailReturn::redirect($request)->with('status', 'Brouillon enregistré dans « Brouillons ».');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $requireRecipient): array
    {
        $maxKb = (int) config('rivo.webmail.attachment_max_mb', 10) * 1024;

        $data = $request->validate([
            'to' => ['nullable', 'string', 'max:5000'],
            'cc' => ['nullable', 'string', 'max:5000'],
            'bcc' => ['nullable', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:1000000'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', "max:{$maxKb}"],
            'reply' => ['nullable', 'array'],
            'reply.folder' => ['required_with:reply', 'string', 'max:300'],
            'reply.uid' => ['required_with:reply', 'integer', 'min:1'],
            'forward' => ['nullable', 'array'],
            'forward.folder' => ['required_with:forward', 'string', 'max:300'],
            'forward.uid' => ['required_with:forward', 'integer', 'min:1'],
            'draft' => ['nullable', 'array'],
            'draft.folder' => ['required_with:draft', 'string', 'max:300'],
            'draft.uid' => ['required_with:draft', 'integer', 'min:1'],
        ], [
            'attachments.*.max' => 'Une pièce jointe dépasse '.($maxKb / 1024).' Mo.',
            'attachments.max' => 'Vingt pièces jointes au plus.',
            'subject.max' => 'L’objet tient en 255 caractères.',
        ]);

        $errors = [];
        $count = 0;
        foreach (['to' => 'À', 'cc' => 'Cc', 'bcc' => 'Cci'] as $field => $label) {
            $parsed = OutgoingMessage::recipients($data[$field] ?? null);
            $count += count($parsed['valid']);

            if ($parsed['invalid'] !== []) {
                $errors[$field] = 'Adresse invalide : '.implode(', ', array_slice($parsed['invalid'], 0, 3)).'.';
            }
        }

        if ($requireRecipient && $count === 0 && ! isset($errors['to'])) {
            $errors['to'] = 'Indiquez au moins un destinataire.';
        }
        if ($count > OutgoingMessage::MAX_RECIPIENTS) {
            $errors['to'] = OutgoingMessage::MAX_RECIPIENTS.' destinataires au plus, copies comprises.';
        }

        $total = collect($request->file('attachments', []))->sum(fn (UploadedFile $file) => $file->getSize());
        if ($total > (int) config('rivo.webmail.attachments_total_mb', 20) * 1024 * 1024) {
            $errors['attachments'] = 'Les pièces jointes dépassent '.config('rivo.webmail.attachments_total_mb', 20).' Mo au total.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function build(Request $request, array $data, WebmailMailbox $box, WebmailAccess $access, WebmailPresenter $presenter): Email
    {
        $forwarded = [];

        try {
            if (isset($data['forward'])) {
                $forwarded = $box->attachmentsOf($data['forward']['folder'], (int) $data['forward']['uid']);
            }

            $original = isset($data['reply']) ? $box->source($data['reply']['folder'], (int) $data['reply']['uid']) : null;
        } catch (WebmailAuthenticationFailed $exception) {
            throw $exception; // mot de passe refusé : redemandé (bootstrap/app.php)
        } catch (WebmailUnavailable $exception) {
            throw ValidationException::withMessages(['webmail' => $exception->getMessage()]);
        }

        return OutgoingMessage::build(
            $box->address(),
            $box->box()->owner,
            $data,
            array_values(array_filter((array) $request->file('attachments', []), fn ($file) => $file instanceof UploadedFile)),
            $forwarded,
            $original,
        );
    }
}
