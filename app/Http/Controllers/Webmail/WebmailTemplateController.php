<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Models\WebmailTemplate;
use App\Support\Webmail\EmailHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** ADR-195 — les modèles de message d'un compte, insérés en rédigeant. */
class WebmailTemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if (WebmailTemplate::query()->where('user_id', $request->user()->id)->count() >= WebmailTemplate::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => WebmailTemplate::MAX_PER_USER.' modèles au plus.']);
        }

        WebmailTemplate::create([...$data, 'user_id' => $request->user()->id]);

        return back()->with('status', "Modèle « {$data['name']} » enregistré.");
    }

    public function update(Request $request, string $template): RedirectResponse
    {
        $model = $this->find($request, $template);
        $model->update($this->validated($request, $model));

        return back()->with('status', 'Modèle modifié.');
    }

    public function destroy(Request $request, string $template): RedirectResponse
    {
        $model = $this->find($request, $template);
        $model->delete();

        return back()->with('status', "Modèle « {$model->name} » supprimé.");
    }

    private function find(Request $request, string $uuid): WebmailTemplate
    {
        return WebmailTemplate::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array{name: string, subject: ?string, body_html: string} */
    private function validated(Request $request, ?WebmailTemplate $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('webmail_templates', 'name')->where('user_id', $request->user()->id)->ignore($current?->id)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
        ], [
            'name.required' => 'Donnez un nom au modèle.',
            'name.unique' => 'Vous avez déjà un modèle de ce nom.',
            'body_html.required' => 'Le modèle est vide.',
        ]);

        $body = EmailHtmlSanitizer::forSending($data['body_html']);

        if (trim(strip_tags($body)) === '') {
            throw ValidationException::withMessages(['body_html' => 'Le modèle est vide.']);
        }

        return ['name' => trim($data['name']), 'subject' => filled($data['subject'] ?? null) ? trim($data['subject']) : null, 'body_html' => $body];
    }
}
