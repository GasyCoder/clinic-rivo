<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Services\Webmail\WebmailMailbox;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-194 — une pièce jointe, toujours téléchargée, jamais affichée dans RIVO :
 * un fichier reçu ne s'exécute pas sous l'adresse de l'application.
 */
class WebmailAttachmentController extends Controller
{
    public function __invoke(string $folder, int $uid, string $part, WebmailMailbox $box): Response
    {
        abort_unless(preg_match('/^[0-9.]{1,20}$/', $part) === 1, 404);

        $file = $box->attachment($folder, $uid, $part);
        abort_if($file === null, 404);

        $name = Str::limit(preg_replace('/[\r\n"\\\\\/]+/', '_', $file['name']) ?: 'piece-jointe', 150, '');

        return response($file['content'], 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.Str::ascii($name).'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
