<?php

namespace App\Services\Pharmacy;

use App\Ai\Agents\SupplierProductMatcher;
use App\Models\AssistantUsage;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Assistant\AssistantErrors;
use App\Services\Assistant\AssistantUsageLedger;
use App\Support\ProductLabel;
use Throwable;

/**
 * ADR-241 — le rapprochement par l'IA, côté portail.
 *
 * Il reçoit les lignes du comparateur d'un site, n'envoie au fournisseur
 * d'IA que les libellés des produits que la règle n'a pas su rapprocher, et
 * rend des paires de lignes de catalogue — des propositions, que le site
 * enregistre « à décider ». Il ne connaît ni prix ni patient.
 */
class SupplierProductAiMatcher
{
    /**
     * ADR-242 — une seule question par lot, assez courte pour que le
     * fournisseur d'IA réponde avant le délai : au plus 70 lignes.
     */
    public const BATCH_LINES = 70;

    /** Produits « ancres » par lot, chacun avec ses voisins les plus proches. */
    public const ANCHORS_PER_BATCH = 10;

    public const NEIGHBORS = 6;

    /** Au-delà, le reste attend une prochaine passe. */
    public const MAX_BATCHES = 40;

    public function __construct(
        private readonly AssistantConfiguration $configuration,
        private readonly AssistantUsageLedger $ledger,
    ) {}

    /**
     * Les lignes qu'on enverrait, pour l'écran : combien, et chez qui.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{item_uuid: string, label: string, supplier: string}>
     */
    public function candidates(array $rows, ?string $family = null): array
    {
        $lines = [];

        foreach ($rows as $row) {
            if (($row['in_clinic_catalog'] ?? true) || ! empty($row['peers']) || ! empty($row['suggestions'])) {
                continue;
            }

            if ($family !== null && $family !== '' && ($row['family'] ?? null) !== $family) {
                continue;
            }

            $item = $row['members']['item_uuids'][0] ?? null;
            $suppliers = collect($row['quotes'] ?? [])->pluck('supplier_name')->filter()->unique()->values();

            if ($item === null || $suppliers->count() !== 1) {
                continue;
            }

            $label = trim((string) $row['name'].(filled($row['unit'] ?? null) ? ' — '.$row['unit'] : ''));
            $lines[] = ['item_uuid' => $item, 'label' => mb_substr($label, 0, 160), 'supplier' => (string) $suppliers->first(), 'family' => $row['family'] ?? null];
        }

        // Un seul fournisseur : rien à rapprocher.
        if (collect($lines)->pluck('supplier')->unique()->count() < 2) {
            return [];
        }

        return $lines;
    }

    /** Pourquoi l'IA ne peut pas tourner pour ce compte, ou null. */
    public function unavailable(User $user): ?string
    {
        if (! $this->configuration->available()) {
            return 'L’assistant IA n’est pas configuré sur le portail (Paramètres › Assistant IA).';
        }

        return $this->ledger->refusal($user)['message'] ?? null;
    }

    /**
     * ADR-242 — les lignes découpées en lots. Peu de lignes : un seul lot, dans
     * l'ordre. Sinon chaque produit du (des) plus petit(s) fournisseur(s) part
     * avec les lignes des autres fournisseurs qui lui ressemblent le plus —
     * mots rares partagés, puis nombres —, pour qu'un lot de 70 lignes porte
     * ses chances de trouver une paire au lieu de 70 produits au hasard. Un
     * produit sans aucun voisin ne part pas : il n'a rien à quoi se comparer.
     *
     * @param  array<int, array{item_uuid: string, label: string, supplier: string, family?: ?string}>  $lines
     * @return array{batches: array<int, array<int, array<string, mixed>>>, remaining: int, alone: int}
     */
    public static function plan(array $lines): array
    {
        $lines = array_values($lines);

        if (count($lines) <= self::BATCH_LINES) {
            return ['batches' => count($lines) >= 2 ? [$lines] : [], 'remaining' => 0, 'alone' => 0];
        }

        $bySupplier = collect($lines)->countBy('supplier');
        $largest = $bySupplier->sortDesc()->keys()->first();

        $words = [];
        $numbers = [];
        $wordIndex = [];
        $numberIndex = [];

        foreach ($lines as $index => $line) {
            $tokens = explode(' ', ProductLabel::key($line['label']));
            $words[$index] = array_values(array_unique(array_filter($tokens, fn (string $token) => strlen($token) >= 3 && ! ctype_digit($token[0]))));
            $numbers[$index] = array_values(array_unique(array_filter($tokens, fn (string $token) => $token !== '' && ctype_digit($token[0]))));

            foreach ($words[$index] as $word) {
                $wordIndex[$word][] = $index;
            }

            foreach ($numbers[$index] as $number) {
                $numberIndex[$number][] = $index;
            }
        }

        $total = count($lines);
        $idf = array_map(fn (array $hits): float => log(1 + $total / count($hits)), $wordIndex);

        $anchors = collect($lines)
            ->filter(fn (array $line) => $line['supplier'] !== $largest)
            ->sortBy(fn (array $line) => mb_strtolower(($line['family'] ?? '~')."\u{0}".ProductLabel::key($line['label'])))
            ->keys()
            ->all();

        $limit = self::MAX_BATCHES * self::ANCHORS_PER_BATCH;
        $remaining = max(0, count($anchors) - $limit);
        $anchors = array_slice($anchors, 0, $limit);

        $groups = [];
        $alone = 0;

        foreach ($anchors as $anchor) {
            $scores = [];

            foreach ($words[$anchor] as $word) {
                foreach ($wordIndex[$word] as $other) {
                    $scores[$other] = ($scores[$other] ?? 0) + $idf[$word];
                }
            }

            foreach ($numbers[$anchor] as $number) {
                // Un nombre trop courant (500, 100…) ne dit rien à lui seul.
                if (count($numberIndex[$number]) > 400) {
                    continue;
                }

                foreach ($numberIndex[$number] as $other) {
                    $scores[$other] = ($scores[$other] ?? 0) + 0.3;
                }
            }

            $scores = array_filter($scores, fn (float $score, int $other) => $lines[$other]['supplier'] !== $lines[$anchor]['supplier'], ARRAY_FILTER_USE_BOTH);
            arsort($scores);
            $neighbors = array_slice(array_keys($scores), 0, self::NEIGHBORS);

            if ($neighbors === []) {
                $alone++;

                continue;
            }

            $groups[] = [$anchor, ...$neighbors];
        }

        $batches = [];

        foreach (array_chunk($groups, self::ANCHORS_PER_BATCH) as $chunk) {
            $indexes = array_slice(array_values(array_unique(array_merge(...$chunk))), 0, self::BATCH_LINES);
            $batches[] = array_map(fn (int $index) => $lines[$index], $indexes);
        }

        return ['batches' => $batches, 'remaining' => $remaining, 'alone' => $alone];
    }

    /**
     * @param  array<int, array{item_uuid: string, label: string, supplier: string}>  $lines
     * @return array{ok: bool, message: string, pairs: array<int, array{item_uuid: string, other_item_uuid: string, reason: ?string}>}
     */
    public function match(User $user, array $lines): array
    {
        if (! $this->configuration->available()) {
            return ['ok' => false, 'message' => 'L’assistant IA n’est pas configuré sur le portail (Paramètres › Assistant IA).', 'pairs' => []];
        }

        if ($refusal = $this->ledger->refusal($user)) {
            return ['ok' => false, 'message' => $refusal['message'], 'pairs' => []];
        }

        if (count($lines) < 2) {
            return ['ok' => true, 'message' => 'Rien à rapprocher : la règle a déjà proposé tout ce qu’elle reconnaît.', 'pairs' => []];
        }

        $provider = (string) $this->configuration->provider()?->value;
        $model = (string) $this->configuration->engineModel();
        $started = hrtime(true);
        $elapsed = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        try {
            $this->configuration->registerProvider();
            $response = (new SupplierProductMatcher($this->configuration))->prompt(self::question($lines));
        } catch (Throwable $exception) {
            $error = AssistantErrors::describe($exception);
            AssistantErrors::log($exception, $error, $provider, $model);
            $this->ledger->record($user, null, $provider, $model, AssistantUsage::STATUS_FAILED, $error['reason'], null, $elapsed());

            return ['ok' => false, 'message' => $error['message'], 'pairs' => []];
        }

        $this->ledger->record($user, null, $provider, $model, AssistantUsage::STATUS_COMPLETED, null, $response->usage, $elapsed());

        $pairs = self::pairs((string) $response->text, $lines);

        return [
            'ok' => true,
            'message' => $pairs === [] ? 'L’IA n’a trouvé aucun produit commun parmi les '.count($lines).' lignes envoyées.' : count($pairs).' rapprochement(s) proposé(s) par l’IA, à confirmer.',
            'pairs' => $pairs,
        ];
    }

    /** @param  array<int, array{item_uuid: string, label: string, supplier: string}>  $lines */
    public static function question(array $lines): string
    {
        $text = ['Voici les lignes, une par produit : numéro. libellé [fournisseur].', ''];

        foreach ($lines as $index => $line) {
            $text[] = ($index + 1).'. '.str_replace(["\n", '[', ']'], ' ', $line['label']).' ['.$line['supplier'].']';
        }

        return implode("\n", $text);
    }

    /**
     * Les paires que la réponse nomme, quand elles ont un sens : deux lignes
     * qui existent, de deux fournisseurs, chacune une seule fois.
     *
     * @param  array<int, array{item_uuid: string, label: string, supplier: string}>  $lines
     * @return array<int, array{item_uuid: string, other_item_uuid: string, reason: ?string}>
     */
    public static function pairs(string $answer, array $lines): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($answer, $start, $end - $start + 1), true);

        if (! is_array($decoded) || ! is_array($decoded['pairs'] ?? null)) {
            return [];
        }

        $pairs = [];
        $seen = [];

        foreach ($decoded['pairs'] as $pair) {
            $a = is_array($pair) ? (int) ($pair['a'] ?? 0) - 1 : -1;
            $b = is_array($pair) ? (int) ($pair['b'] ?? 0) - 1 : -1;

            if (! isset($lines[$a], $lines[$b]) || $a === $b || $lines[$a]['supplier'] === $lines[$b]['supplier']) {
                continue;
            }

            $key = min($a, $b).'-'.max($a, $b);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $why = is_string($pair['why'] ?? null) ? mb_substr(trim($pair['why']), 0, 300) : null;
            $pairs[] = ['item_uuid' => $lines[$a]['item_uuid'], 'other_item_uuid' => $lines[$b]['item_uuid'], 'reason' => $why ?: null];
        }

        return array_slice($pairs, 0, 100);
    }
}
