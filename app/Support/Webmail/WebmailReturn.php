<?php

namespace App\Support\Webmail;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-194 — où revenir après une action ou un envoi.
 *
 * `back()` suffit presque toujours. Pas quand l'action fait disparaître le message
 * qu'on lit (archivé, mis à la corbeille, brouillon envoyé) : revenir à son adresse
 * mènerait à « introuvable ». L'écran dit alors où aller — un chemin de la
 * messagerie, et rien d'autre : jamais une adresse extérieure.
 */
final class WebmailReturn
{
    public static function redirect(Request $request): RedirectResponse
    {
        $to = (string) $request->input('return_to', '');

        if (preg_match('#^/messagerie(/[A-Za-z0-9._~%/-]*)?(\?[A-Za-z0-9._~%&=+-]*)?$#', $to) === 1 && ! str_contains($to, '//') && ! str_contains($to, '..')) {
            return redirect($to);
        }

        return back();
    }
}
