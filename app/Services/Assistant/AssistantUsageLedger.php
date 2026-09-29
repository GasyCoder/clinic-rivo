<?php

namespace App\Services\Assistant;

use App\Models\AssistantUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * ADR-222 — la consommation de l'assistant sur cette base : les quotas qu'on vérifie
 * avant d'appeler le fournisseur, la ligne écrite après chaque question, et le
 * tableau que le Super Administrateur lit dans les paramètres.
 *
 * Trois garde-fous, du plus fin au plus large :
 *
 *   par heure     le limiteur « assistant-ai » (RateLimiter) — réglable
 *   par jour      un nombre de questions par compte — facultatif
 *   par mois      un budget de tokens pour toute la base — facultatif
 *
 * Aucun montant n'est calculé : les prix des fournisseurs changent, et RIVO n'en
 * connaît aucun. Les tokens sont ceux que le fournisseur a lui-même comptés.
 */
class AssistantUsageLedger
{
    public function __construct(private readonly AssistantConfiguration $configuration) {}

    /**
     * La raison pour laquelle ce compte ne peut pas poser de question maintenant,
     * ou null.
     *
     * @return array{reason: string, message: string}|null
     */
    public function refusal(User $user): ?array
    {
        if (! $this->installed()) {
            return null;
        }

        $daily = $this->configuration->dailyLimitPerUser();

        if ($daily !== null && $this->questionsToday($user) >= $daily) {
            return [
                'reason' => 'daily_limit',
                'message' => "Vous avez atteint la limite de {$daily} questions par jour. L’assistant sera de nouveau disponible demain.",
            ];
        }

        $budget = $this->configuration->monthlyTokenBudget();

        if ($budget !== null && $this->tokensThisMonth() >= $budget) {
            return [
                'reason' => 'monthly_budget',
                'message' => 'Le budget mensuel de l’assistant est atteint pour cet établissement. Il reprendra le mois prochain, ou quand l’administrateur l’aura relevé.',
            ];
        }

        return null;
    }

    public function record(
        ?User $user,
        ?string $conversationId,
        string $provider,
        string $model,
        string $status,
        ?string $error,
        ?TextUsage $usage,
        int $durationMs,
    ): void {
        if (! $this->installed()) {
            return;
        }

        AssistantUsage::create([
            'user_id' => $user?->getKey(),
            'conversation_id' => $conversationId,
            'provider' => mb_substr($provider, 0, 40),
            'model' => mb_substr($model, 0, 150),
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 40) : null,
            'input_tokens' => max(0, (int) ($usage?->inputTokens ?? 0)),
            'output_tokens' => max(0, (int) ($usage?->outputTokens ?? 0)),
            'duration_ms' => max(0, $durationMs),
            'created_at' => now(),
        ]);
    }

    public function questionsToday(User $user): int
    {
        return AssistantUsage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    public function tokensThisMonth(): int
    {
        return (int) AssistantUsage::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    /**
     * Le tableau de consommation des paramètres : aujourd'hui, ce mois, les 14
     * derniers jours et les comptes qui l'utilisent le plus.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if (! $this->installed()) {
            return ['available' => false];
        }

        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $aggregate = fn ($query) => $query->selectRaw(
            'COUNT(*) as questions, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COUNT(DISTINCT user_id) as users',
            [AssistantUsage::STATUS_FAILED],
        )->first();

        $todayRow = $aggregate(AssistantUsage::query()->where('created_at', '>=', $today));
        $monthRow = $aggregate(AssistantUsage::query()->where('created_at', '>=', $month));

        $since = now()->subDays(13)->startOfDay();
        $perDay = AssistantUsage::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'input_tokens', 'output_tokens'])
            ->groupBy(fn (AssistantUsage $usage) => $usage->created_at->toDateString());

        $days = collect(range(13, 0))->map(function (int $offset) use ($perDay): array {
            $date = now()->subDays($offset)->toDateString();
            $rows = $perDay->get($date, collect());

            return [
                'date' => $date,
                'questions' => $rows->count(),
                'tokens' => (int) $rows->sum(fn (AssistantUsage $usage) => $usage->input_tokens + $usage->output_tokens),
            ];
        })->values()->all();

        $topUsers = AssistantUsage::query()
            ->where('created_at', '>=', $month)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('COUNT(*) as questions'), DB::raw('SUM(input_tokens + output_tokens) as tokens'))
            ->groupBy('user_id')
            ->orderByDesc('questions')
            ->limit(5)
            ->get();

        $names = User::query()->whereKey($topUsers->pluck('user_id'))->pluck('name', 'id');
        $budget = $this->configuration->monthlyTokenBudget();
        $monthTokens = (int) $monthRow->input_tokens + (int) $monthRow->output_tokens;

        return [
            'available' => true,
            'today' => $this->row($todayRow),
            'month' => [
                ...$this->row($monthRow),
                'label' => ucfirst(Carbon::now()->locale('fr')->translatedFormat('F Y')),
            ],
            'budget' => [
                'tokens' => $budget,
                'used' => $monthTokens,
                'percent' => $budget ? min(100, (int) round($monthTokens / $budget * 100)) : null,
            ],
            'days' => $days,
            'top_users' => $topUsers->map(fn ($row) => [
                'name' => $names[$row->user_id] ?? 'Compte supprimé',
                'questions' => (int) $row->questions,
                'tokens' => (int) $row->tokens,
            ])->all(),
        ];
    }

    /** @return array{questions: int, failed: int, input_tokens: int, output_tokens: int, users: int} */
    private function row(?object $row): array
    {
        return [
            'questions' => (int) ($row->questions ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'users' => (int) ($row->users ?? 0),
        ];
    }

    private function installed(): bool
    {
        try {
            return Schema::hasTable('ai_assistant_usages');
        } catch (Throwable) {
            return false;
        }
    }
}
