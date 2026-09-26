<?php

namespace App\Http\Controllers\Webmail;

use App\Http\Controllers\Controller;
use App\Models\WebmailLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ADR-195 — les libellés d'un compte : un nom et une couleur. Le mot-clé posé sur
 * les messages ne change jamais ; supprimer un libellé le retire de la liste, les
 * messages gardent un mot-clé que plus rien n'affiche.
 */
class WebmailLabelController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if (WebmailLabel::query()->where('user_id', $request->user()->id)->count() >= WebmailLabel::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => WebmailLabel::MAX_PER_USER.' libellés au plus.']);
        }

        WebmailLabel::create([...$data, 'user_id' => $request->user()->id, 'keyword' => WebmailLabel::newKeyword()]);

        return back()->with('status', "Libellé « {$data['name']} » créé.");
    }

    public function update(Request $request, string $label): RedirectResponse
    {
        $model = $this->find($request, $label);
        $model->update($this->validated($request, $model));

        return back()->with('status', 'Libellé modifié.');
    }

    public function destroy(Request $request, string $label): RedirectResponse
    {
        $model = $this->find($request, $label);
        $model->delete();

        return back()->with('status', "Libellé « {$model->name} » supprimé.");
    }

    private function find(Request $request, string $uuid): WebmailLabel
    {
        return WebmailLabel::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array{name: string, color: string} */
    private function validated(Request $request, ?WebmailLabel $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('webmail_labels', 'name')->where('user_id', $request->user()->id)->ignore($current?->id)],
            'color' => ['required', Rule::in(WebmailLabel::COLORS)],
        ], [
            'name.required' => 'Donnez un nom au libellé.',
            'name.unique' => 'Vous avez déjà un libellé de ce nom.',
            'name.max' => 'Le nom tient en 40 caractères.',
        ]);

        return ['name' => trim($data['name']), 'color' => $data['color']];
    }
}
