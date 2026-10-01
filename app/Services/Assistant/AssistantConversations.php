<?php

namespace App\Services\Assistant;

use App\Ai\Agents\ClinicAssistant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

/**
 * ADR-222 — les conversations de l'assistant, gardées par le SDK dans cette base
 * (`agent_conversations`). Toute lecture passe par le propriétaire : on ne cherche
 * jamais une conversation par son seul identifiant.
 */
class AssistantConversations
{
    /** Les conversations proposées dans l'historique. */
    public const RECENT = 20;

    /** Les messages rechargés quand on reprend une conversation. */
    public const TRANSCRIPT = 40;

    public function belongsTo(string $conversationId, User $user): bool
    {
        if (! Str::isUuid($conversationId) || ! $this->installed()) {
            return false;
        }

        return $this->owned($user)->whereKey($conversationId)->exists();
    }

    /** @return list<array{id: string, title: string, updated_at: ?string}> */
    public function recent(User $user): array
    {
        if (! $this->installed()) {
            return [];
        }

        return $this->owned($user)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from((new ConversationMessage)->getTable())
                ->whereColumn('conversation_id', (new Conversation)->getTable().'.id')
                ->where('agent', ClinicAssistant::class))
            ->orderByDesc('updated_at')
            ->limit(self::RECENT)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'title' => (string) $conversation->title,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array{id: string, title: string, messages: list<array{role: string, content: string, created_at: ?string}>} */
    public function transcript(string $conversationId): array
    {
        $conversation = Conversation::query()->findOrFail($conversationId);

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit(self::TRANSCRIPT)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->filter(fn (ConversationMessage $message) => trim((string) $message->content) !== '')
            ->map(fn (ConversationMessage $message) => [
                'role' => $message->role,
                'content' => (string) $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['id' => $conversation->id, 'title' => (string) $conversation->title, 'messages' => $messages];
    }

    public function delete(string $conversationId): void
    {
        DB::transaction(function () use ($conversationId): void {
            ConversationMessage::query()->where('conversation_id', $conversationId)->delete();
            Conversation::query()->whereKey($conversationId)->delete();
        });
    }

    private function owned(User $user)
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user));
    }

    private function installed(): bool
    {
        try {
            return Schema::hasTable((new Conversation)->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
