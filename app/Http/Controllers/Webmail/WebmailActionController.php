<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Models\WebmailLabel;
use App\Services\Audit\Auditor;
use App\Services\Webmail\WebmailMailbox;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;
use App\Support\Webmail\WebmailReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-195 — une action sur un ou plusieurs messages : lu, favori, archiver,
 * indésirable, corbeille, supprimer définitivement, déplacer, libeller. Seule la
 * suppression définitive est auditée : c'est la seule qu'on ne rattrape pas.
 */
class WebmailActionController extends Controller
{
    public const ACTIONS = ['read', 'unread', 'star', 'unstar', 'archive', 'spam', 'inbox', 'trash', 'delete', 'move', 'label', 'unlabel'];

    public function __invoke(Request $request, WebmailMailbox $box, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.folder' => ['required', 'string', 'max:300'],
            'items.*.uid' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(self::ACTIONS)],
            'target' => ['nullable', 'required_if:action,move', 'string', 'max:300'],
            'label' => ['nullable', Rule::requiredIf(fn () => in_array($request->input('action'), ['label', 'unlabel'], true)), 'uuid'],
        ], [
            'items.required' => 'Choisissez au moins un message.',
            'items.max' => 'Cent messages au plus à la fois.',
            'target.required_if' => 'Choisissez un dossier de destination.',
            'label.required' => 'Choisissez un libellé.',
        ]);

        $label = null;
        if (in_array($validated['action'], ['label', 'unlabel'], true)) {
            $label = WebmailLabel::query()->where('user_id', $request->user()->id)->where('uuid', $validated['label'])->first();
            abort_if($label === null, 404);
        }

        try {
            $status = $box->act($validated['items'], $validated['action'], $validated['target'] ?? null, $label);
        } catch (WebmailAuthenticationFailed $exception) {
            throw $exception; // mot de passe refusé : redemandé (bootstrap/app.php)
        } catch (WebmailUnavailable $exception) {
            return back()->withErrors(['webmail' => $exception->getMessage()]);
        }

        if ($validated['action'] === 'delete') {
            $auditor->record('webmail.delete', newValues: ['address' => $box->address(), 'count' => count($validated['items'])], module: 'webmail');
        }

        return WebmailReturn::redirect($request)->with('status', $status);
    }
}
