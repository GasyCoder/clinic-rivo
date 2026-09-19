<?php

namespace App\Services\Patient;

use App\Enums\EpisodeStatus;
use App\Enums\PaymentStatus;
use App\Models\Episode;
use App\Models\PatientVipSetting;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Qui est un patient VIP sur ce site ? (ADR-133)
 *
 * Un patient VIP est un patient à la fois très fréquent **et** qui a beaucoup
 * apporté à la clinique, sur une fenêtre glissante :
 *
 *     passages non annulés  >= min_episodes
 *     ET encaissements réels >= min_amount     (sur les `window_months` derniers mois)
 *
 * Les deux seuils sont réglés par site depuis le portail Super Administration.
 * **Sans réglage, personne n'est VIP** : aucun seuil n'est inventé. Le statut
 * n'est jamais stocké, il est recalculé à chaque lecture — donc dynamique.
 *
 * « Encaissé » est ce que la Caisse a réellement reçu (`payments` COMPLETED,
 * ADR-012), jamais un montant facturé : une prise en charge à 100 % ou une
 * facture impayée n'est pas de l'argent entré (ADR-047). Une vente sans dossier
 * patient ne compte pour personne.
 */
final class PatientVipClassifier
{
    private ?PatientVipSetting $settings;

    /** @var array<int, int>|null */
    private ?array $vipIds = null;

    public function __construct(?PatientVipSetting $settings = null)
    {
        $this->settings = $settings ?? PatientVipSetting::current();
    }

    public static function current(): self
    {
        return new self;
    }

    public function settings(): ?PatientVipSetting
    {
        return $this->settings;
    }

    /** Un réglage actif : sans lui, aucun patient n'est VIP. */
    public function isConfigured(): bool
    {
        return $this->settings !== null && $this->settings->enabled;
    }

    /**
     * Les patients VIP, par identifiant local. Deux requêtes agrégées, jamais
     * une par patient : le second agrégat ne porte que sur ceux qui ont déjà
     * assez de passages.
     *
     * @return array<int, int>
     */
    public function vipIds(): array
    {
        if ($this->vipIds !== null) {
            return $this->vipIds;
        }

        if (! $this->isConfigured()) {
            return $this->vipIds = [];
        }

        $cutoff = now()->subMonths($this->settings->window_months);

        $frequent = Episode::query()
            ->where('status', '!=', EpisodeStatus::Cancelled->value)
            ->where('started_at', '>=', $cutoff)
            ->groupBy('patient_id')
            ->havingRaw('COUNT(*) >= ?', [$this->settings->min_episodes])
            ->pluck('patient_id')
            ->all();

        if ($frequent === []) {
            return $this->vipIds = [];
        }

        return $this->vipIds = Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('payments.status', PaymentStatus::Completed->value)
            ->whereIn('invoices.patient_id', $frequent)
            ->where(DB::raw('COALESCE(payments.paid_at, payments.created_at)'), '>=', $cutoff)
            ->groupBy('invoices.patient_id')
            // PDO lie tout nombre décimal comme un texte, et SQLite range tout nombre
            // avant tout texte : sans conversion explicite, `SUM(...) >= '100000'`
            // y serait toujours faux. `CAST` compare deux nombres sur les deux moteurs.
            ->havingRaw('SUM(payments.amount) >= CAST(? AS DECIMAL(15,2))', [(string) $this->settings->min_amount])
            ->pluck('invoices.patient_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isVip(int $patientId): bool
    {
        return in_array($patientId, $this->vipIds(), true);
    }

    /**
     * La règle en une phrase, pour l'écran : « au moins 5 passages et
     * 1 000 000 Ar encaissés sur les 12 derniers mois ».
     */
    public function rule(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $amount = number_format((float) $this->settings->min_amount, 0, ',', ' ');
        $months = $this->settings->window_months;

        return sprintf(
            'au moins %d passage%s et %s Ar encaissés sur les %s',
            $this->settings->min_episodes,
            $this->settings->min_episodes > 1 ? 's' : '',
            str_replace(' ', "\u{202F}", $amount),
            $months > 1 ? "{$months} derniers mois" : 'dernier mois',
        );
    }
}
