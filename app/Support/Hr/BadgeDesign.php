<?php

namespace App\Support\Hr;

use App\Enums\BadgeLogoStyle;
use App\Services\Settings\AppSettings;

/**
 * ADR-209 — l'apparence du badge du personnel sur ce site : ce que le portail a
 * réglé et, à défaut, le modèle bleu et jaune de la clinique.
 *
 * Un seul modèle pour tout le personnel — médecin, infirmier, gardien, RH,
 * stagiaire. Se règlent (amendement du 2026-09-27) : les couleurs, les textes,
 * ce qui s'affiche, l'icône, les polices et leurs tailles, la disposition de la
 * carte et la mise en page de l'impression. Le même texte sert l'aperçu des
 * paramètres et chaque badge imprimé. Une colonne vide = la valeur de la clinique.
 */
final class BadgeDesign
{
    public const DEFAULT_PRIMARY = '#1B4FA3';

    public const DEFAULT_ACCENT = '#F6C318';

    public const DEFAULT_TAGLINE = 'Votre santé, notre priorité';

    public const DEFAULT_INTERN_LABEL = 'Stagiaire';

    public const DEFAULT_NUMBER_LABEL = 'N°';

    /** L'emblème de la clinique, découpé dans son logo : une adresse de l'application, que le portail sert aussi. */
    public const DEFAULT_EMBLEM_URL = '/images/brand/clinic-saint-georges-emblem.png';

    /** Vide = la couleur de la clinique (principale, accent) ou celle tirée de la principale (texte, fond). */
    public const COLORS = ['badge_primary_color', 'badge_accent_color', 'badge_text_color', 'badge_background_color'];

    /** Les textes libres, et leur longueur maximale. */
    public const TEXTS = [
        'badge_tagline' => 80,
        'badge_seal_top' => 30,
        'badge_seal_bottom' => 40,
        'badge_intern_label' => 24,
        'badge_number_label' => 12,
        'badge_footer_text' => 60,
    ];

    /** Les réglages à choix : la première valeur est celle de la clinique. */
    public const CHOICES = [
        'badge_logo_style' => ['SEAL', 'LOGO', 'NONE'],
        // AUTO : l'icône suit la fonction ; sinon, la même pour tout le monde.
        'badge_icon' => [
            'AUTO', 'STETHOSCOPE', 'SYRINGE', 'HEART_PULSE', 'HOSPITAL', 'AMBULANCE', 'BRIEFCASE_MEDICAL',
            'SHIELD_PLUS', 'HAND_HEART', 'MICROSCOPE', 'PILL', 'BABY', 'BADGE_CHECK', 'ID_CARD', 'USER',
        ],
        'badge_font' => ['SANS', 'ARIAL', 'SERIF'],
        'badge_tagline_font' => ['SCRIPT', 'SANS', 'SERIF'],
        'badge_name_case' => ['UPPER', 'AS_IS'],
        'badge_name_order' => ['FIRST_LAST', 'LAST_FIRST'],
        'badge_text_case' => ['UPPER', 'AS_IS'],
        'badge_orientation' => ['PORTRAIT', 'LANDSCAPE'],
        'badge_card_size' => ['STANDARD', 'LARGE', 'XLARGE'],
        'badge_photo_shape' => ['CIRCLE', 'ROUNDED'],
        'badge_corners' => ['ROUNDED', 'SQUARE'],
        'badge_paper' => ['A4', 'A5', 'A3', 'LETTER', 'CARD'],
        'badge_paper_orientation' => ['PORTRAIT', 'LANDSCAPE'],
    ];

    /** Les nombres : [minimum, maximum, valeur de la clinique]. Tailles en %, marges en mm. */
    public const NUMBERS = [
        'badge_name_size' => [70, 150, 100],
        'badge_text_size' => [70, 150, 100],
        'badge_tagline_size' => [70, 150, 100],
        'badge_page_margin' => [0, 25, 8],
        'badge_gap' => [0, 20, 4],
    ];

    /** Ce qui s'affiche, et sa valeur quand personne ne l'a réglé. */
    public const SWITCHES = [
        'badge_show_photo' => true,
        'badge_show_tagline' => true,
        'badge_show_icon' => true,
        'badge_show_department' => true,
        'badge_show_job' => true,
        'badge_show_number' => true,
        'badge_show_validity' => true,
        'badge_show_site' => false,
        'badge_show_watermark' => true,
        'badge_show_decorations' => true,
        'badge_cut_marks' => true,
    ];

    /** Le format de la carte (ISO/CEI 7810 ID-1), en portrait, et l'agrandissement de chaque taille. */
    public const CARD_MM = ['width' => 54, 'height' => 85.6];

    public const CARD_SCALES = ['STANDARD' => 1.0, 'LARGE' => 1.15, 'XLARGE' => 1.3];

    /** Les papiers, en portrait (mm). `CARD` : une carte par page, pour une imprimante à badges. */
    public const PAPERS_MM = [
        'A4' => [210, 297],
        'A5' => [148, 210],
        'A3' => [297, 420],
        'LETTER' => [215.9, 279.4],
    ];

    /** Chaque réglage du badge, dans l'ordre des colonnes. */
    public const FIELDS = [
        'badge_primary_color', 'badge_accent_color', 'badge_text_color', 'badge_background_color',
        'badge_tagline', 'badge_seal_top', 'badge_seal_bottom', 'badge_intern_label', 'badge_number_label', 'badge_footer_text',
        'badge_logo_style', 'badge_icon', 'badge_font', 'badge_tagline_font', 'badge_name_case', 'badge_name_order', 'badge_text_case',
        'badge_orientation', 'badge_card_size', 'badge_photo_shape', 'badge_corners', 'badge_paper', 'badge_paper_orientation',
        'badge_name_size', 'badge_text_size', 'badge_tagline_size', 'badge_page_margin', 'badge_gap',
        'badge_show_photo', 'badge_show_tagline', 'badge_show_icon', 'badge_show_department', 'badge_show_job',
        'badge_show_number', 'badge_show_validity', 'badge_show_site', 'badge_show_watermark', 'badge_show_decorations',
        'badge_cut_marks',
    ];

    public function __construct(private readonly AppSettings $settings) {}

    public function style(): BadgeLogoStyle
    {
        return BadgeLogoStyle::tryFrom((string) $this->settings->setting()?->badge_logo_style) ?? BadgeLogoStyle::DEFAULT;
    }

    /**
     * Le fichier déposé que le badge montre : l'emblème du badge s'il y en a un ;
     * en « Logo seul », à défaut, le logo du site. `null` : rien de déposé, le
     * badge prend l'image de la clinique ({@see fallbackEmblemUrl()}).
     */
    public function emblemKind(): ?string
    {
        if ($this->settings->assetExists('badge')) {
            return 'badge';
        }

        return $this->style() === BadgeLogoStyle::Logo && $this->settings->assetExists('logo') ? 'logo' : null;
    }

    /** L'image de la clinique : son emblème rond pour le sceau, son logo en largeur sinon. */
    public function fallbackEmblemUrl(): string
    {
        return $this->style() === BadgeLogoStyle::Logo
            ? (string) (config('rivo.documents.logo_url') ?: self::DEFAULT_EMBLEM_URL)
            : self::DEFAULT_EMBLEM_URL;
    }

    /**
     * La valeur de chaque réglage telle que le formulaire des paramètres la montre :
     * un choix, un nombre ou un interrupteur jamais réglé prend la valeur de la
     * clinique ; une couleur ou un texte vide reste vide (le champ montre alors ce
     * qui s'applique).
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $setting = $this->settings->setting();
        $values = [];

        foreach (self::COLORS as $field) {
            $values[$field] = $this->color($setting?->{$field}, null);
        }

        foreach (array_keys(self::TEXTS) as $field) {
            $values[$field] = $setting?->{$field};
        }

        foreach (array_keys(self::CHOICES) as $field) {
            $values[$field] = $this->choice($field);
        }

        foreach (array_keys(self::NUMBERS) as $field) {
            $values[$field] = $this->number($field);
        }

        foreach (self::SWITCHES as $field => $default) {
            $values[$field] = $this->switch($field);
        }

        return $values;
    }

    /**
     * @param  string  $servedUrl  l'adresse qui sert le fichier déposé pour ce site
     *                             (lue seulement quand un fichier l'est)
     * @return array<string, mixed>
     */
    public function toArray(string $servedUrl): array
    {
        $setting = $this->settings->setting();
        $brand = $this->settings->brand();

        return [
            'primary' => $this->color($setting?->badge_primary_color, self::DEFAULT_PRIMARY),
            'accent' => $this->color($setting?->badge_accent_color, self::DEFAULT_ACCENT),
            // Vide : l'écran les tire de la couleur principale.
            'text' => $this->color($setting?->badge_text_color, null),
            'background' => $this->color($setting?->badge_background_color, null),
            'tagline' => $this->filled($setting?->badge_tagline) ?? self::DEFAULT_TAGLINE,
            'intern_label' => $this->filled($setting?->badge_intern_label) ?? self::DEFAULT_INTERN_LABEL,
            'number_label' => $this->filled($setting?->badge_number_label) ?? self::DEFAULT_NUMBER_LABEL,
            'footer_text' => $this->filled($setting?->badge_footer_text),
            'logo_style' => $this->style()->value,
            'emblem_url' => $this->emblemKind() ? $servedUrl : $this->fallbackEmblemUrl(),
            'brand' => $brand,
            'site' => (string) config('rivo.site.name', ''),
            'seal' => self::seal($brand, $setting?->badge_seal_top, $setting?->badge_seal_bottom),
            'icon' => $this->choice('badge_icon'),
            'font' => $this->choice('badge_font'),
            'tagline_font' => $this->choice('badge_tagline_font'),
            'name_case' => $this->choice('badge_name_case'),
            'name_order' => $this->choice('badge_name_order'),
            'text_case' => $this->choice('badge_text_case'),
            'orientation' => $this->choice('badge_orientation'),
            'card_size' => $this->choice('badge_card_size'),
            'photo_shape' => $this->choice('badge_photo_shape'),
            'corners' => $this->choice('badge_corners'),
            'name_size' => $this->number('badge_name_size'),
            'text_size' => $this->number('badge_text_size'),
            'tagline_size' => $this->number('badge_tagline_size'),
            'show_photo' => $this->switch('badge_show_photo'),
            'show_tagline' => $this->switch('badge_show_tagline'),
            'show_icon' => $this->switch('badge_show_icon'),
            'show_department' => $this->switch('badge_show_department'),
            'show_job' => $this->switch('badge_show_job'),
            'show_number' => $this->switch('badge_show_number'),
            'show_validity' => $this->switch('badge_show_validity'),
            'show_site' => $this->switch('badge_show_site'),
            'show_watermark' => $this->switch('badge_show_watermark'),
            'show_decorations' => $this->switch('badge_show_decorations'),
            // La mise en page proposée à l'impression ; le RH peut la changer pour une impression.
            'print' => [
                'paper' => $this->choice('badge_paper'),
                'orientation' => $this->choice('badge_paper_orientation'),
                'margin' => $this->number('badge_page_margin'),
                'gap' => $this->number('badge_gap'),
                'cut_marks' => $this->switch('badge_cut_marks'),
            ],
        ];
    }

    /**
     * Le texte autour du sceau. Réglé (l'une ou l'autre ligne) : les deux lignes
     * réglées, en capitales. Sinon, le nom de l'établissement coupé.
     *
     * @return array{top: string, bottom: string}
     */
    public static function seal(string $brand, ?string $top, ?string $bottom): array
    {
        $top = trim((string) $top);
        $bottom = trim((string) $bottom);

        if ($top === '' && $bottom === '') {
            return self::sealText($brand);
        }

        return ['top' => mb_strtoupper($top), 'bottom' => mb_strtoupper($bottom)];
    }

    /**
     * Le nom de l'établissement autour du sceau : le premier mot en haut, le reste
     * en bas — « CLINIQUE » / « SAINT GEORGES ». Un nom d'un mot reste en haut.
     *
     * @return array{top: string, bottom: string}
     */
    public static function sealText(string $brand): array
    {
        $words = preg_split('/\s+/u', trim($brand), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $top = array_shift($words) ?? '';

        return [
            'top' => mb_strtoupper($top),
            'bottom' => mb_strtoupper(implode(' ', $words)),
        ];
    }

    /**
     * La carte en millimètres, dans son orientation.
     *
     * @return array{width: float, height: float}
     */
    public static function cardMm(?string $size, ?string $orientation): array
    {
        $scale = self::CARD_SCALES[$size] ?? 1.0;
        $width = round(self::CARD_MM['width'] * $scale, 1);
        $height = round(self::CARD_MM['height'] * $scale, 1);

        return $orientation === 'LANDSCAPE' ? ['width' => $height, 'height' => $width] : ['width' => $width, 'height' => $height];
    }

    /**
     * Combien de cartes tiennent sur une page, marges et espacement compris.
     * `null` pour une carte par page : la page prend la taille de la carte.
     */
    public static function perPage(?string $paper, ?string $paperOrientation, ?string $size, ?string $orientation, int $margin, int $gap): ?int
    {
        if (! isset(self::PAPERS_MM[$paper])) {
            return null;
        }

        [$width, $height] = self::PAPERS_MM[$paper];
        if ($paperOrientation === 'LANDSCAPE') {
            [$width, $height] = [$height, $width];
        }

        $card = self::cardMm($size, $orientation);
        $columns = (int) floor(($width - 2 * $margin + $gap) / ($card['width'] + $gap));
        $rows = (int) floor(($height - 2 * $margin + $gap) / ($card['height'] + $gap));

        return max(0, $columns) * max(0, $rows);
    }

    private function choice(string $field): string
    {
        $value = (string) $this->settings->setting()?->{$field};

        return in_array($value, self::CHOICES[$field], true) ? $value : self::CHOICES[$field][0];
    }

    private function number(string $field): int
    {
        [$min, $max, $default] = self::NUMBERS[$field];
        $value = $this->settings->setting()?->{$field};

        return $value === null ? $default : max($min, min($max, (int) $value));
    }

    private function switch(string $field): bool
    {
        $value = $this->settings->setting()?->{$field};

        return $value === null ? self::SWITCHES[$field] : (bool) $value;
    }

    private function color(?string $value, ?string $default): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $default;
    }

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
