<?php

namespace App\Models;

use App\Enums\PartnerCategory;
use App\Enums\PartnerProfession;
use App\Enums\PatientSex;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ADR-211 — un partenaire de la clinique, distinct d'une mutuelle.
 *
 * Médical : une personne (nom, prénom, métier) qui peut venir elle-même en
 * patient — `patient_id` relie alors sa fiche à son dossier patient. Autre : un
 * organisme ou une personne (ISPSG, TsaraShop…), choisi à la prise en charge
 * d'un passage. Le mode Partenaire ne couvre encore aucun montant (voir
 * EpisodeFinancialMode::Partner).
 *
 * `name` est le nom affiché partout (prise en charge, instantané des passages) :
 * « Nom Prénom » pour un partenaire médical, le nom ou l'identité sinon.
 */
#[Fillable([
    'category', 'name', 'last_name', 'first_name', 'profession', 'profession_detail',
    'sex', 'birth_date', 'phone', 'email', 'address_entry_id', 'address', 'notes', 'patient_id', 'active',
])]
class PartnerOrganization extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $partner): void {
            if ($partner->category === PartnerCategory::Medical) {
                $partner->last_name = Str::squish((string) $partner->last_name);
                $partner->first_name = filled($partner->first_name) ? Str::squish($partner->first_name) : null;
                $partner->name = trim($partner->last_name.' '.($partner->first_name ?? ''));
            } else {
                $partner->last_name = null;
                $partner->first_name = null;
                $partner->profession = null;
                $partner->profession_detail = null;
                $partner->sex = null;
                $partner->birth_date = null;
            }

            if ($partner->profession !== PartnerProfession::Other) {
                $partner->profession_detail = null;
            }

            $partner->name = Str::squish($partner->name);
            $partner->normalized_name = self::normalize($partner->name);
        });
    }

    public static function normalize(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    protected function casts(): array
    {
        return [
            'category' => PartnerCategory::class,
            'profession' => PartnerProfession::class,
            'sex' => PatientSex::class,
            'birth_date' => 'date',
            'active' => 'boolean',
        ];
    }

    /** Un partenaire proposé : ni désactivé, ni archivé. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function isMedical(): bool
    {
        return $this->category === PartnerCategory::Medical;
    }

    /** « Médecin », ou la précision d'un « Autre métier ». */
    public function professionLabel(): ?string
    {
        if ($this->profession === PartnerProfession::Other) {
            return $this->profession_detail ?: PartnerProfession::Other->label();
        }

        return $this->profession?->label();
    }

    /** L'adresse du référentiel du site ; `address` en garde le libellé. */
    public function addressEntry(): BelongsTo
    {
        return $this->belongsTo(AddressEntry::class)->withTrashed();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function episodeCoverages(): HasMany
    {
        return $this->hasMany(EpisodePartnerCoverage::class);
    }

    /** Une fiche qui a servi (un passage, un dossier patient) ne se supprime jamais. */
    public function isForceDeleteProtected(): bool
    {
        return $this->patient_id !== null || $this->episodeCoverages()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
