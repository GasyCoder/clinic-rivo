<?php

namespace App\Http\Requests\Assistant;

use App\Services\Assistant\AssistantConfiguration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-222 — une question à l'assistant. Le droit `ai_assistant.use` est vérifié par
 * la route ; ici, la forme : une question courte, une conversation désignée par son
 * identifiant, et l'endroit où l'on se trouve (adresse, écran, section) — jamais le
 * contenu de la page.
 */
class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai_assistant.use') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.AssistantConfiguration::MAX_QUESTION_LENGTH],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'page' => ['nullable', 'array:path,component,section'],
            'page.path' => ['nullable', 'string', 'max:255'],
            'page.component' => ['nullable', 'string', 'max:120'],
            'page.section' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.required' => 'Écrivez votre question.',
            'message.max' => 'La question tient en '.AssistantConfiguration::MAX_QUESTION_LENGTH.' caractères au plus : raccourcissez-la.',
            'conversation_id.uuid' => 'Cette conversation n’existe pas.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }
}
