<?php

namespace App\Services\Settings;

use App\Enums\AuthTemplate;
use App\Enums\ProfileTemplate;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ce que l'application affiche d'elle-même sur ce déploiement (ADR-184).
 *
 * Chaque valeur vient des paramètres réglés depuis le portail et, à défaut,
 * de la configuration de déploiement : un site que personne n'a réglé garde
 * exactement son nom, son logo et son identité légale d'avant.
 *
 * Lu à chaque page (props partagées, en-tête HTML) : la ligne est lue une fois
 * par requête (liaison `scoped`). Une base dont la migration n'est pas encore
 * jouée ne doit pas rendre toute l'application inaccessible (constat de
 * l'ADR-100) : sans table, tout retombe sur la configuration.
 */
class AppSettings
{
    public const ASSET_KINDS = ['logo', 'icon', 'signature', 'background'];

    /**
     * Le nom de chaque fichier, et son genre : « Icône enregistrée », pas
     * « enregistré ».
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const ASSET_LABELS = [
        'logo' => ['Logo', false],
        'icon' => ['Icône', true],
        'signature' => ['Signature', true],
        'background' => ['Image de fond', true],
    ];

    /** Largeur de l'aperçu d'une image de fond envoyé au portail : une photo entière y pèserait des mégaoctets. */
    private const PREVIEW_WIDTH = 640;

    public const CURRENCY_LABELS = ['Ar', 'Ariary', 'MGA'];

    public const DEFAULT_BABY_MAX_AGE = 1;

    public const DEFAULT_CHILD_MAX_AGE = 15;

    public const DEFAULT_DIRECTOR_TITLE = 'Directeur général';

    public const DISK = 'local';

    /**
     * Ce que chaque réponse dit aux moteurs de recherche quand l'application
     * leur est masquée : ni indexer, ni suivre, ni garder en cache, ni extrait,
     * ni image.
     */
    public const ROBOTS_DIRECTIVES = 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    /** Ce qu'un enregistrement répond tant que les migrations ne sont pas jouées sur cette base. */
    public const NOT_INSTALLED_MESSAGE = 'Les paramètres ne sont pas à jour sur cette base : '
        .'les migrations doivent d’abord être jouées (php artisan migrate).';

    /** La dernière colonne ajoutée : présente, toutes les migrations des paramètres sont jouées. */
    private const LATEST_COLUMN = 'profile_template';

    private ?AppSetting $setting = null;

    private bool $loaded = false;

    public function setting(): ?AppSetting
    {
        if (! $this->loaded) {
            $this->loaded = true;

            try {
                $this->setting = AppSetting::current();
            } catch (Throwable) {
                $this->setting = null;
            }
        }

        return $this->setting;
    }

    /**
     * Lire se passe de la table (tout retombe sur la configuration) ; écrire,
     * non. Sans elle, un enregistrement répond par une phrase qui dit quoi
     * faire — jamais par l'erreur SQL brute.
     *
     * @throws ValidationException
     */
    public function ensureInstalled(string $errorKey): void
    {
        try {
            $installed = Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', self::LATEST_COLUMN);
        } catch (Throwable) {
            $installed = false;
        }

        if (! $installed) {
            throw ValidationException::withMessages([$errorKey => self::NOT_INSTALLED_MESSAGE]);
        }
    }

    /** Après une écriture : la prochaine lecture relit la base. */
    public function forget(): void
    {
        $this->loaded = false;
        $this->setting = null;
    }

    public function brand(): string
    {
        return $this->filled($this->setting()?->app_name) ?? (string) config('rivo.brand');
    }

    /** La devise de l'établissement : réglée pour le site, sinon celle du déploiement ; `null`, rien ne s'affiche. */
    public function tagline(): ?string
    {
        return $this->filled($this->setting()?->app_tagline) ?? $this->filled(config('rivo.tagline'));
    }

    /**
     * L'application est-elle masquée aux moteurs de recherche ? Réglé pour le
     * site, sinon la configuration du déploiement — masquée par défaut.
     */
    public function hiddenFromSearchEngines(): bool
    {
        $hidden = $this->setting()?->search_engines_hidden;

        return $hidden === null ? (bool) config('rivo.search_engines.hidden', true) : (bool) $hidden;
    }

    /**
     * Le robots.txt de ce déploiement. Masquée, l'application refuse toute
     * exploration ; visible, elle garde le fichier d'origine de Laravel.
     */
    public function robotsTxt(): string
    {
        if (! $this->hiddenFromSearchEngines()) {
            return "User-agent: *\nDisallow:\n";
        }

        return "# Application privée : aucune page à explorer ni à indexer (ADR-184).\n"
            ."User-agent: *\nDisallow: /\n";
    }

    public function primaryColor(): ?string
    {
        return $this->filled($this->setting()?->primary_color);
    }

    public function logoUrl(): ?string
    {
        return $this->setting()?->logo_path
            ? $this->assetUrl('logo')
            : config('rivo.documents.logo_url');
    }

    /** Le modèle des pages d'authentification ; une valeur inconnue retombe sur « Couverture ». */
    public function authTemplate(): AuthTemplate
    {
        return AuthTemplate::tryFrom((string) $this->setting()?->auth_template) ?? AuthTemplate::DEFAULT;
    }

    /** Le modèle de la page « Mon profil ». */
    public function profileTemplate(): ProfileTemplate
    {
        return ProfileTemplate::tryFrom((string) $this->setting()?->profile_template) ?? ProfileTemplate::DEFAULT;
    }

    /** L'image de fond des pages d'authentification : déposée pour le site, sinon celle du déploiement. */
    public function authBackgroundUrl(): ?string
    {
        return $this->setting()?->auth_background_path
            ? $this->assetUrl('background')
            : (config('rivo.auth_cover_url') ?: null);
    }

    /**
     * « Icône du portail enregistrée pour ce site. » — le participe s'accorde
     * avec le fichier.
     */
    public static function assetMessage(string $kind, string $participle, string $suffix = '', string $owner = ''): string
    {
        [$label, $feminine] = self::ASSET_LABELS[$kind];

        return $label.$owner.' '.$participle.($feminine ? 'e' : '').$suffix.'.';
    }

    /** L'icône carrée : favicon et pastille de la barre latérale. Absente, la pastille garde les initiales. */
    public function iconUrl(): ?string
    {
        return $this->setting()?->icon_path ? $this->assetUrl('icon') : null;
    }

    /** @return array{label: string, position: string, decimals: int} */
    public function currency(): array
    {
        $setting = $this->setting();
        $label = $setting?->currency_label;

        return [
            'label' => in_array($label, self::CURRENCY_LABELS, true) ? $label : 'Ar',
            'position' => $setting?->currency_position === 'before' ? 'before' : 'after',
            'decimals' => $setting?->currency_decimals === 2 ? 2 : 0,
        ];
    }

    /** Un montant écrit comme le site l'a réglé, pour un message ou un libellé côté serveur. */
    public function formatMoney(float|int|string|null $amount): string
    {
        $currency = $this->currency();
        $value = (float) $amount;
        $decimals = $currency['decimals'] === 2 || floor($value) != $value ? 2 : 0;
        $number = number_format($value, $decimals, ',', "\u{202F}");

        return $currency['position'] === 'before' ? "{$currency['label']} {$number}" : "{$number} {$currency['label']}";
    }

    /** @return array{baby_max_age: int, child_max_age: int} */
    public function ageBands(): array
    {
        $setting = $this->setting();

        return [
            'baby_max_age' => $setting?->baby_max_age ?? self::DEFAULT_BABY_MAX_AGE,
            'child_max_age' => $setting?->child_max_age ?? self::DEFAULT_CHILD_MAX_AGE,
        ];
    }

    /**
     * L'identité imprimée sur les documents, les mêmes clés qu'avant
     * (`config('rivo.documents')`) plus la banque : chaque écran qui la lit
     * suit les paramètres sans avoir à changer.
     *
     * @return array<string, string|null>
     */
    public function documents(): array
    {
        $setting = $this->setting();

        return [
            'logo_url' => $this->logoUrl(),
            'nif' => $this->filled($setting?->legal_nif) ?? config('rivo.documents.nif'),
            'stat' => $this->filled($setting?->legal_stat) ?? config('rivo.documents.stat'),
            'address' => $this->filled($setting?->legal_address) ?? config('rivo.documents.address'),
            'phone' => $this->filled($setting?->legal_phone) ?? config('rivo.documents.phone'),
            'email' => $this->filled($setting?->legal_email) ?? config('rivo.documents.email'),
            'bank_name' => $this->filled($setting?->bank_name),
            'bank_account' => $this->filled($setting?->bank_account),
        ];
    }

    /** @return array{name: string|null, title: string, has_signature: bool} */
    public function director(): array
    {
        $setting = $this->setting();

        return [
            'name' => $this->filled($setting?->director_name),
            'title' => $this->filled($setting?->director_title) ?? self::DEFAULT_DIRECTOR_TITLE,
            'has_signature' => (bool) $setting?->signature_path && $this->assetExists('signature'),
        ];
    }

    /**
     * La signature comme donnée intégrée (`data:`), pour qu'un document signé
     * garde la signature du jour où il a été produit — la remplacer ensuite ne
     * réécrit aucun document déjà remis. Jamais servie par une adresse publique.
     */
    public function signatureDataUri(): ?string
    {
        return $this->assetDataUri('signature');
    }

    public function assetPath(string $kind): ?string
    {
        return match ($kind) {
            'logo' => $this->setting()?->logo_path,
            'icon' => $this->setting()?->icon_path,
            'signature' => $this->setting()?->signature_path,
            'background' => $this->setting()?->auth_background_path,
            default => null,
        };
    }

    public function assetExists(string $kind): bool
    {
        $path = $this->assetPath($kind);

        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }

    public function assetDataUri(string $kind): ?string
    {
        if (! $this->assetExists($kind)) {
            return null;
        }

        $path = $this->assetPath($kind);
        $disk = Storage::disk(self::DISK);

        return 'data:'.($disk->mimeType($path) ?: 'application/octet-stream').';base64,'.base64_encode((string) $disk->get($path));
    }

    /**
     * L'aperçu que le portail affiche. Une image de fond est réduite (JPEG,
     * 640 px de large) : la page du portail réunit plusieurs sites, et des
     * photos entières y pèseraient plusieurs mégaoctets chacune. Sans GD, ou
     * sur une image illisible, pas d'aperçu — le fichier reste « présent ».
     */
    public function assetPreviewDataUri(string $kind): ?string
    {
        if ($kind !== 'background') {
            return $this->assetDataUri($kind);
        }

        if (! $this->assetExists($kind) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) Storage::disk(self::DISK)->get($this->assetPath($kind)));

            if ($image === false) {
                return null;
            }

            $preview = imagescale($image, min(self::PREVIEW_WIDTH, imagesx($image)));
            ob_start();
            imagejpeg($preview ?: $image, null, 75);

            return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * L'adresse publique d'un logo, d'une icône ou de l'image de fond. La version change à chaque
     * remplacement : un navigateur ne garde jamais l'ancienne image en cache.
     */
    public function assetUrl(string $kind): string
    {
        return route('branding.show', ['kind' => $kind, 'v' => $this->setting()?->updated_at?->timestamp ?? 0], absolute: false);
    }

    /**
     * La couleur principale réglée, déclinée pour le thème clair et le sombre.
     * Seules les variables de marque changent — primaire, anneau de focus,
     * accent — ; les couleurs d'état (rouge, ambre, vert) restent celles qui
     * disent le danger ou la réussite.
     */
    public function themeCss(): ?string
    {
        $hex = $this->primaryColor();

        if ($hex === null || ! ThemeColor::isValid($hex)) {
            return null;
        }

        $theme = ThemeColor::fromHex($hex);

        return ':root{'
            .'--primary:'.$theme->light().';--ring:'.$theme->light().';'
            .'--primary-foreground:'.$theme->lightForeground().';'
            .'--accent:'.$theme->lightAccent().';--accent-foreground:'.$theme->lightAccentForeground().';'
            .'}.dark{'
            .'--primary:'.$theme->dark().';--ring:'.$theme->dark().';'
            .'--primary-foreground:'.$theme->darkForeground().';'
            .'--accent:'.$theme->darkAccent().';--accent-foreground:'.$theme->darkAccentForeground().';'
            .'}';
    }

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
