<?php

namespace App\Services\Webmail;

use App\Models\ProfessionalMailbox;

/**
 * ADR-194 — la boîte ouverte dans la messagerie : l'adresse, son titulaire et
 * son site. Une valeur, pas un modèle : sur le portail, l'adresse vit dans la
 * base d'un site et n'est connue que par son API (ADR-004) ; sur un site, elle
 * vient de la base locale. La messagerie ne lit rien d'autre sur elle.
 *
 * `own` dit si c'est la boîte du compte connecté (sa fiche employé) ou celle
 * d'un autre employé, ouverte avec le droit `webmail.open_any`.
 */
final class WebmailBox
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $address,
        public readonly string $owner,
        public readonly ?string $job,
        public readonly ?string $siteCode,
        public readonly ?string $siteName,
        public readonly bool $own,
    ) {}

    public static function fromMailbox(ProfessionalMailbox $mailbox, bool $own): self
    {
        $mailbox->loadMissing('employee.jobTitle');
        $employee = $mailbox->employee;
        $name = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));

        return new self(
            uuid: (string) $mailbox->uuid,
            address: (string) $mailbox->address,
            owner: $name !== '' ? $name : (string) $mailbox->address,
            job: $employee?->jobTitle?->label ?? $employee?->profession ?? null,
            siteCode: config('rivo.site.code'),
            siteName: config('rivo.site.name'),
            own: $own,
        );
    }

    /**
     * Une adresse telle que l'API d'un site la présente (ProfessionalMailboxPresenter).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromSitePayload(array $data, string $siteCode, string $siteName): ?self
    {
        if (! is_string($data['uuid'] ?? null) || ! is_string($data['address'] ?? null)) {
            return null;
        }

        $name = trim((string) ($data['employee']['name'] ?? ''));

        return new self(
            uuid: $data['uuid'],
            address: $data['address'],
            owner: $name !== '' ? $name : $data['address'],
            job: is_string($data['employee']['job_title'] ?? null) ? $data['employee']['job_title'] : null,
            siteCode: $siteCode,
            siteName: $siteName,
            own: false,
        );
    }

    public function is(self $other): bool
    {
        return $this->uuid === $other->uuid && $this->address === $other->address;
    }

    /** @return array{uuid: string, address: string, owner: string, job: ?string, site_code: ?string, site_name: ?string, own: bool} */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'address' => $this->address,
            'owner' => $this->owner,
            'job' => $this->job,
            'site_code' => $this->siteCode,
            'site_name' => $this->siteName,
            'own' => $this->own,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['uuid'] ?? null) || ! is_string($data['address'] ?? null)) {
            return null;
        }

        return new self(
            uuid: $data['uuid'],
            address: $data['address'],
            owner: is_string($data['owner'] ?? null) ? $data['owner'] : $data['address'],
            job: is_string($data['job'] ?? null) ? $data['job'] : null,
            siteCode: is_string($data['site_code'] ?? null) ? $data['site_code'] : null,
            siteName: is_string($data['site_name'] ?? null) ? $data['site_name'] : null,
            own: (bool) ($data['own'] ?? false),
        );
    }
}
