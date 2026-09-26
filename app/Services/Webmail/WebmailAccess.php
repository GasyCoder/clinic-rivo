<?php

namespace App\Services\Webmail;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Models\User;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-194 (amendement du 2026-09-25) — qui ouvre quelle boîte : c'est une
 * question de permissions.
 *
 *  - `webmail.view`     : la messagerie apparaît, et le compte ouvre SA boîte —
 *                          l'adresse professionnelle active de la fiche employé
 *                          reliée au compte (ADR-188, ADR-190) ;
 *  - `webmail.open_any` : le compte ouvre aussi la boîte d'un autre employé — sur
 *                          son site, ou, depuis le portail, sur n'importe quel site
 *                          (le Super Admin l'a, comme toutes les permissions, ADR-186).
 *
 * Aucune des deux ne dispense du mot de passe de la boîte : c'est le serveur de
 * messagerie qui l'exige, et RIVO ne le connaît pas (ADR-190).
 */
class WebmailAccess
{
    public const VIEW = 'webmail.view';

    public const OPEN_ANY = 'webmail.open_any';

    /** Les boîtes des sites, lues par leur API, gardées quelques minutes pour les contacts. */
    private const PORTAL_CACHE_SECONDS = 300;

    /** @var array<int, ?ProfessionalMailbox> */
    private array $ownRecords = [];

    /** @var array<int, ?WebmailBox> */
    private array $current = [];

    public function __construct(
        private readonly WebmailSession $session,
        private readonly PortalSiteApiClient $sites,
    ) {}

    public static function onPortal(): bool
    {
        return config('rivo.site.type') === 'admin';
    }

    /** La messagerie apparaît : sa propre boîte, ou celle des autres. */
    public function canUse(?User $user): bool
    {
        return $user !== null && ($user->can(self::VIEW) || $user->can(self::OPEN_ANY));
    }

    public function canOpenAny(?User $user): bool
    {
        return $user !== null && $user->can(self::OPEN_ANY);
    }

    /** L'adresse active de la fiche employé reliée au compte — sur un site seulement. */
    public function ownRecord(?User $user): ?ProfessionalMailbox
    {
        if ($user === null || self::onPortal() || ! $this->canUse($user)) {
            return null;
        }

        if (! array_key_exists($user->id, $this->ownRecords)) {
            try {
                $this->ownRecords[$user->id] = ProfessionalMailbox::query()
                    ->with('employee.jobTitle')
                    ->where('status', ProfessionalMailboxStatus::Active->value)
                    ->whereHas('employee', fn ($query) => $query->where('user_id', $user->id))
                    ->latest('id')
                    ->first();
            } catch (\Throwable) {
                $this->ownRecords[$user->id] = null;
            }
        }

        return $this->ownRecords[$user->id];
    }

    public function ownBox(?User $user): ?WebmailBox
    {
        $record = $this->ownRecord($user);

        return $record !== null ? WebmailBox::fromMailbox($record, own: true) : null;
    }

    /**
     * La boîte ouverte, ou à ouvrir : celle choisie dans cette session si le compte
     * y a toujours droit, sinon la sienne. Rien sans permission.
     */
    public function current(?User $user): ?WebmailBox
    {
        if ($user === null || ! $this->canUse($user)) {
            return null;
        }

        if (! array_key_exists($user->id, $this->current)) {
            $chosen = $this->session->box();
            $this->current[$user->id] = $chosen !== null && $this->allowed($user, $chosen)
                ? $chosen
                : $this->ownBox($user);
        }

        return $this->current[$user->id];
    }

    /** Le compte a une boîte à ouvrir : la sienne, ou celles des autres. */
    public function hasSomethingToOpen(?User $user): bool
    {
        return $this->ownBox($user) !== null || $this->canOpenAny($user);
    }

    /**
     * Les boîtes des autres employés, pour `webmail.open_any` : celles du site, ou,
     * sur le portail, celles de chaque site, lues par son API (jamais sa base).
     * Seules les adresses actives : une boîte suspendue ne s'ouvre pas.
     *
     * @return list<WebmailBox>
     */
    public function others(User $user, bool $fresh = false): array
    {
        if (! $this->canOpenAny($user)) {
            return [];
        }

        if (! self::onPortal()) {
            $own = $this->ownRecord($user);

            return ProfessionalMailbox::query()
                ->with('employee.jobTitle')
                ->where('status', ProfessionalMailboxStatus::Active->value)
                ->when($own, fn ($query) => $query->whereKeyNot($own->getKey()))
                ->get()
                ->map(fn (ProfessionalMailbox $mailbox) => WebmailBox::fromMailbox($mailbox, own: false))
                ->sortBy(fn (WebmailBox $box) => mb_strtolower($box->owner))
                ->values()
                ->all();
        }

        $key = 'webmail.portal-boxes.'.$user->id;

        if ($fresh) {
            Cache::forget($key);
        }

        $rows = Cache::remember($key, self::PORTAL_CACHE_SECONDS, fn () => $this->portalBoxes($user));

        return array_values(array_filter(array_map(fn (array $row) => WebmailBox::fromArray($row), $rows)));
    }

    /** Une boîte d'un autre employé, relue à l'instant — jamais celle que le navigateur décrit. */
    public function findOther(User $user, string $uuid, ?string $siteCode): ?WebmailBox
    {
        foreach ($this->others($user, fresh: true) as $box) {
            if ($box->uuid === $uuid && (! self::onPortal() || $box->siteCode === $siteCode)) {
                return $box;
            }
        }

        return null;
    }

    /**
     * Les sites que le portail n'a pas pu lire : leurs boîtes manquent à la liste,
     * et l'écran le dit plutôt que de laisser croire qu'il n'y en a pas.
     *
     * @return list<string>
     */
    public function unreachableSites(User $user): array
    {
        return self::onPortal() ? (Cache::get('webmail.portal-unreachable.'.$user->id) ?? []) : [];
    }

    /**
     * Pourquoi le compte n'a aucune boîte à ouvrir, pour l'écran qui le dit :
     * `permission`, `unlinked` (aucune fiche employé), `no_address` (la fiche n'a
     * pas d'adresse), `inactive` (une adresse existe, demandée ou suspendue).
     *
     * @return array{reason: string, status: ?string}
     */
    public function unavailableReason(User $user): array
    {
        if (! $this->canUse($user)) {
            return ['reason' => 'permission', 'status' => null];
        }

        if (self::onPortal()) {
            return ['reason' => 'permission', 'status' => null];
        }

        $employee = Employee::query()->where('user_id', $user->id)->first();

        if ($employee === null) {
            return ['reason' => 'unlinked', 'status' => null];
        }

        $latest = ProfessionalMailbox::query()->where('employee_id', $employee->id)->latest('id')->first();

        if ($latest === null) {
            return ['reason' => 'no_address', 'status' => null];
        }

        return ['reason' => 'inactive', 'status' => $latest->status->label()];
    }

    private function allowed(User $user, WebmailBox $box): bool
    {
        if ($box->own) {
            $own = $this->ownBox($user);

            return $own !== null && $own->is($box);
        }

        if (! $this->canOpenAny($user)) {
            return false;
        }

        // Sur le portail, la boîte vit sur un site : c'est son serveur de messagerie
        // qui refuse une adresse suspendue (ADR-190, suspend_login) à chaque requête.
        if (self::onPortal()) {
            return true;
        }

        return ProfessionalMailbox::query()
            ->where('uuid', $box->uuid)
            ->where('address', $box->address)
            ->where('status', ProfessionalMailboxStatus::Active->value)
            ->exists();
    }

    /** @return list<array<string, mixed>> */
    private function portalBoxes(User $user): array
    {
        $rows = [];
        $unreachable = [];

        foreach ($this->sites->professionalMailboxesForAllSites($user) as $result) {
            $site = $result['site'] ?? [];

            if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
                $name = (string) ($site['name'] ?? $site['code'] ?? '?');
                $unreachable[] = ($result['status'] ?? null) === 'UNCONFIGURED' ? $name.' (API non configurée)' : $name;

                continue;
            }

            foreach ($result['data'] as $mailbox) {
                if (! is_array($mailbox) || ($mailbox['status'] ?? null) !== ProfessionalMailboxStatus::Active->value) {
                    continue;
                }

                $box = WebmailBox::fromSitePayload($mailbox, (string) ($site['code'] ?? ''), (string) ($site['name'] ?? ''));

                if ($box !== null) {
                    $rows[] = $box->toArray();
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['site_name'], mb_strtolower($a['owner'])] <=> [$b['site_name'], mb_strtolower($b['owner'])]);
        Cache::put('webmail.portal-unreachable.'.$user->id, $unreachable, self::PORTAL_CACHE_SECONDS);

        return $rows;
    }
}
