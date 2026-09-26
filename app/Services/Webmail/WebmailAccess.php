<?php

namespace App\Services\Webmail;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Employee;
use App\Models\ProfessionalMailbox;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

/**
 * ADR-195 (amendement du 2026-09-25) — qui ouvre quelle boîte : c'est une
 * question de permissions.
 *
 *  - `webmail.view`     : la messagerie apparaît, et le compte ouvre SA boîte —
 *                          l'adresse professionnelle active de la fiche employé
 *                          reliée au compte (ADR-188, ADR-190) ;
 *  - `webmail.open_any` : le compte ouvre aussi la boîte d'un autre employé de
 *                          son site.
 *
 * Aucune des deux ne dispense du mot de passe de la boîte : c'est le serveur de
 * messagerie qui l'exige, et RIVO ne le connaît pas (ADR-190).
 *
 * Le portail (amendement du 2026-09-26) n'ouvre qu'une boîte : la sienne, réglée
 * dans son .env (adresse et mot de passe, comme l'accès cPanel). Le Super Admin y
 * arrive directement, sans saisie ni choix ; il n'ouvre plus la boîte d'un employé.
 * Les adresses actives des sites restent proposées comme destinataires.
 */
class WebmailAccess
{
    public const VIEW = 'webmail.view';

    public const OPEN_ANY = 'webmail.open_any';

    /** Les adresses des sites, lues par leur API, gardées quelques minutes pour les destinataires. */
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

    /** Ouvrir la boîte d'un autre employé : sur un site seulement — le portail n'ouvre que la sienne. */
    public function canOpenAny(?User $user): bool
    {
        return $user !== null && ! self::onPortal() && $user->can(self::OPEN_ANY);
    }

    /**
     * La boîte du portail, réglée dans son .env : `null` hors du portail, ou tant que
     * l'adresse et son mot de passe n'y sont pas renseignés.
     */
    public function portalBox(): ?WebmailBox
    {
        $address = mb_strtolower(trim((string) config('rivo.webmail.portal.address')));

        if (! self::onPortal() || $address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL) || blank(config('rivo.webmail.portal.password'))) {
            return null;
        }

        $name = trim((string) config('rivo.webmail.portal.name'));

        return new WebmailBox(
            // Un identifiant stable, tiré de l'adresse : la boîte n'existe dans aucune base.
            uuid: Uuid::uuid5(Uuid::NAMESPACE_URL, 'mailto:'.$address)->toString(),
            address: $address,
            owner: $name !== '' ? $name : app(AppSettings::class)->brand(),
            job: 'Portail Super Administration',
            siteCode: null,
            siteName: null,
            own: true,
            portal: true,
        );
    }

    /**
     * Le mot de passe de la boîte : celui du .env pour la boîte du portail, sinon
     * celui saisi dans cette session.
     */
    public function password(?WebmailBox $box): ?string
    {
        if ($box === null) {
            return null;
        }

        if ($box->portal) {
            $portal = $this->portalBox();

            return $portal !== null && $portal->is($box) ? (string) config('rivo.webmail.portal.password') : null;
        }

        return $this->session->passwordFor($box);
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
        if (self::onPortal()) {
            return $this->canUse($user) ? $this->portalBox() : null;
        }

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
     * Les boîtes des autres employés du site, pour `webmail.open_any`. Seules les
     * adresses actives : une boîte suspendue ne s'ouvre pas.
     *
     * @return list<WebmailBox>
     */
    public function others(User $user): array
    {
        if (! $this->canOpenAny($user)) {
            return [];
        }

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

    /** Une boîte d'un autre employé, relue à l'instant — jamais celle que le navigateur décrit. */
    public function findOther(User $user, string $uuid): ?WebmailBox
    {
        foreach ($this->others($user) as $box) {
            if ($box->uuid === $uuid) {
                return $box;
            }
        }

        return null;
    }

    /**
     * Sur le portail, les adresses actives de chaque site, lues par son API (jamais
     * sa base) : des destinataires proposés à la frappe, pas des boîtes à ouvrir.
     * Un site injoignable n'en propose simplement aucune.
     *
     * @return list<WebmailBox>
     */
    public function siteAddresses(User $user): array
    {
        if (! self::onPortal() || ! $this->canUse($user)) {
            return [];
        }

        $rows = Cache::remember('webmail.portal-boxes.'.$user->id, self::PORTAL_CACHE_SECONDS, fn () => $this->portalBoxes($user));

        return array_values(array_filter(array_map(fn (array $row) => WebmailBox::fromArray($row), $rows)));
    }

    /**
     * Pourquoi le compte n'a aucune boîte à ouvrir, pour l'écran qui le dit :
     * `permission`, `unlinked` (aucune fiche employé), `no_address` (la fiche n'a
     * pas d'adresse), `inactive` (une adresse existe, demandée ou suspendue),
     * `portal_unconfigured` (le .env du portail ne règle pas sa boîte).
     *
     * @return array{reason: string, status: ?string}
     */
    public function unavailableReason(User $user): array
    {
        if (! $this->canUse($user)) {
            return ['reason' => 'permission', 'status' => null];
        }

        if (self::onPortal()) {
            return ['reason' => 'portal_unconfigured', 'status' => null];
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

        foreach ($this->sites->professionalMailboxesForAllSites($user) as $result) {
            $site = $result['site'] ?? [];

            if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
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

        return $rows;
    }
}
