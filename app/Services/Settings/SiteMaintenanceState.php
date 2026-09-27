<?php

namespace App\Services\Settings;

use App\Models\SiteMaintenance;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-193 — la maintenance de ce site, telle qu'une requête la voit : lue une
 * fois par requête (liaison `scoped`), comme les paramètres de l'application.
 *
 * Elle ne vaut que sur un site clinique : tous les comptes du portail sont Super
 * Administrateurs et détiennent toutes les permissions (ADR-027, ADR-186), si
 * bien qu'une maintenance du portail ne fermerait rien à personne.
 *
 * Une base dont la migration n'est pas jouée ne doit jamais fermer le site ni le
 * rendre inaccessible (constat de l'ADR-100) : sans table, pas de maintenance.
 */
class SiteMaintenanceState
{
    public const BYPASS_PERMISSION = 'app_maintenance.bypass';

    public const MANAGE_PERMISSION = 'app_maintenance.update';

    /** Le bandeau prévient les personnes connectées pendant les 24 heures qui précèdent le début. */
    public const WARNING_HOURS = 24;

    public const DEFAULT_TITLE = 'Maintenance en cours';

    public const DEFAULT_MESSAGE = 'Le site est momentanément indisponible pour une opération de maintenance. '
        .'Merci de votre patience : il sera de nouveau accessible dès la fin de l’intervention.';

    private ?SiteMaintenance $current = null;

    private bool $loaded = false;

    /** Seul un site clinique peut être mis en maintenance. */
    public static function applies(): bool
    {
        return config('rivo.site.type') === 'clinic';
    }

    public function current(): ?SiteMaintenance
    {
        if (! $this->loaded) {
            $this->loaded = true;

            try {
                $this->current = self::applies() ? SiteMaintenance::current() : null;
            } catch (Throwable) {
                $this->current = null;
            }
        }

        return $this->current;
    }

    public function isActive(): bool
    {
        return (bool) $this->current()?->isActive();
    }

    /** Ce compte traverse la maintenance : le droit dédié, jamais un nom de rôle (ADR-152). */
    public function canBypass(?User $user): bool
    {
        return $user !== null && $user->can(self::BYPASS_PERMISSION);
    }

    /**
     * Lire se passe de la table (pas de maintenance) ; écrire, non. Sans elle, la
     * commande répond par une phrase qui dit quoi faire — jamais par l'erreur SQL.
     *
     * @throws ValidationException
     */
    public static function ensureInstalled(): void
    {
        try {
            $installed = Schema::hasTable('site_maintenances');
        } catch (Throwable) {
            $installed = false;
        }

        if (! $installed) {
            throw ValidationException::withMessages([
                'maintenance' => 'La maintenance n’est pas encore installée sur ce site : ses migrations doivent d’abord être jouées (php artisan migrate).',
            ]);
        }
    }

    /** Après une écriture : la prochaine lecture relit la base. */
    public function forget(): void
    {
        $this->loaded = false;
        $this->current = null;
    }

    /** Les secondes jusqu'à la fin prévue, pour l'en-tête `Retry-After` ; `null` sans fin prévue. */
    public function retryAfterSeconds(): ?int
    {
        $ends = $this->current()?->ends_at;

        return $ends !== null ? max(60, (int) now()->diffInSeconds($ends, false)) : null;
    }

    /**
     * Ce que la page de maintenance affiche. Rien de plus que le message choisi et
     * la fenêtre : jamais qui l'a posée, ni pourquoi.
     *
     * @return array{title: string, message: string, starts_at: ?string, ends_at: ?string}
     */
    public function notice(): array
    {
        $maintenance = $this->current();

        return [
            'title' => filled($maintenance?->title) ? (string) $maintenance->title : self::DEFAULT_TITLE,
            'message' => filled($maintenance?->message) ? (string) $maintenance->message : self::DEFAULT_MESSAGE,
            'starts_at' => $maintenance?->starts_at?->toIso8601String(),
            'ends_at' => $maintenance?->ends_at?->toIso8601String(),
        ];
    }

    /**
     * La prop partagée `site.maintenance` : `null` quand rien n'est à dire.
     *
     *   ACTIVE    le site est fermé ; `bypassing` dit si ce compte le traverse
     *             (bandeau rouge pour lui, avertissement sur la page de connexion)
     *   UPCOMING  une maintenance commence dans moins de WARNING_HOURS heures
     *
     * @return array<string, mixed>|null
     */
    public function sharedProp(?User $user): ?array
    {
        $maintenance = $this->current();

        if ($maintenance === null) {
            return null;
        }

        if ($maintenance->isActive()) {
            return [
                'state' => SiteMaintenance::STATE_ACTIVE,
                'bypassing' => $this->canBypass($user),
                ...$this->notice(),
            ];
        }

        if ($maintenance->isUpcoming() && $maintenance->starts_at->lte(now()->addHours(self::WARNING_HOURS))) {
            return [
                'state' => SiteMaintenance::STATE_UPCOMING,
                'bypassing' => false,
                ...$this->notice(),
            ];
        }

        return null;
    }
}
