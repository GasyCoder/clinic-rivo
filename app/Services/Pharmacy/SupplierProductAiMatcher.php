<?php

namespace App\Services\Pharmacy;

use App\Ai\Agents\SupplierProductMatcher;
use App\Models\AssistantUsage;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Assistant\AssistantErrors;
use App\Services\Assistant\AssistantUsageLedger;
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
    /** Au-delà, la liste ne tient plus dans une question raisonnable. */
    public const MAX_LINES = 150;

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

        return array_slice($lines, 0, self::MAX_LINES);
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
