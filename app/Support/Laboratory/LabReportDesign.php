<?php

namespace App\Support\Laboratory;

use App\Services\Settings\AppSettings;

/**
 * ADR-223 — l'aspect du compte rendu d'analyses (le PDF de l'ADR-218) sur ce site :
 * ce que le portail a réglé et, à défaut, le compte rendu tel qu'il était.
 *
 * Se règlent : le modèle, la police et sa taille, les couleurs (accent, texte,
 * fond des titres de section, valeurs hors norme), l'en-tête (logo, nom,
 * sous-titre, titre du document, coordonnées, NIF/STAT), le QR du numéro de
 * laboratoire, la colonne Antériorité, les lignes alternées, chaque ligne du bloc
 * final (envoyé, validé, édité, identité), qui signe, le pied de page (texte, site
 * web, patient, numéros de page). Une colonne vide = la valeur d'origine : un site
 * que personne n'a réglé imprime le compte rendu d'avant.
 *
 * Aucun réglage ne touche aux résultats eux-mêmes : valeurs, unités, références,
 * notes et validation sont ceux du dossier (ADR-218, ADR-216).
 */
final class LabReportDesign
{
    /** Le repli quand le site n'a pas de couleur principale (celui du compte rendu d'avant). */
    public const DEFAULT_ACCENT = '#1D4ED8';

    public const DEFAULT_TEXT = '#111111';

    public const DEFAULT_SUBHEADING = 'Laboratoire d’analyses médicales';

    public const DEFAULT_LAB_SIGNATORY = 'Le responsable du laboratoire';

    public const DEFAULT_PHYSICIAN_SIGNATORY = 'Le médecin';

    /**
     * Vide = la couleur principale du site (accent), le noir d'origine (texte), aucun
     * fond (titres de section, bloc du patient), le texte (valeurs hors norme).
     */
    public const COLORS = ['lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background', 'lab_report_abnormal_color'];

    /** Les textes libres, et leur longueur maximale. */
    public const TEXTS = [
        'lab_report_heading' => 80,
        'lab_report_subheading' => 120,
        'lab_report_title' => 80,
        'lab_report_lab_signatory' => 60,
        'lab_report_physician_signatory' => 60,
        'lab_report_footer_text' => 120,
        'lab_report_website' => 120,
    ];

    /** Les réglages à choix : la première valeur est celle d'origine. */
    public const CHOICES = [
        'lab_report_template' => ['CLASSIC', 'BANNER', 'MINIMAL'],
        'lab_report_font' => ['SANS', 'SERIF'],
        // Qui signe : le laboratoire, un médecin, les deux, ou le médecin qui a demandé l'analyse.
        'lab_report_signatory' => ['LAB', 'PHYSICIAN', 'BOTH', 'AUTO'],
    ];

    /** Les nombres : [minimum, maximum, valeur d'origine]. La taille du texte en %. */
    public const NUMBERS = [
        'lab_report_font_size' => [90, 120, 100],
    ];

    /** Ce qui s'affiche, et sa valeur quand personne ne l'a réglé. */
    public const SWITCHES = [
        'lab_report_show_logo' => true,
        'lab_report_show_contacts' => true,
        'lab_report_show_legal' => true,
        'lab_report_show_anteriority' => true,
        'lab_report_zebra' => false,
        'lab_report_show_sent' => true,
        'lab_report_show_approval' => true,
        'lab_report_show_generated' => true,
        'lab_report_show_closing_identity' => true,
        // Le pied de chaque page : l'établissement, le patient, le site web.
        'lab_report_show_footer' => true,
        'lab_report_show_footer_patient' => true,
        'lab_report_show_page_numbers' => true,
        // Le QR du numéro de laboratoire — et rien d'autre : le scanner rouvre la demande (ADR-214).
        'lab_report_show_qr' => false,
    ];

    /** Chaque réglage du compte rendu, dans l'ordre des colonnes. */
    public const FIELDS = [
        'lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background',
        'lab_report_abnormal_color', 'lab_report_heading', 'lab_report_subheading', 'lab_report_title', 'lab_report_lab_signatory',
        'lab_report_physician_signatory', 'lab_report_footer_text', 'lab_report_website',
        'lab_report_template', 'lab_report_font', 'lab_report_signatory',
        'lab_report_font_size',
        'lab_report_show_logo', 'lab_report_show_contacts', 'lab_report_show_legal', 'lab_report_show_anteriority',
        'lab_report_zebra', 'lab_report_show_sent', 'lab_report_show_approval', 'lab_report_show_generated',
        'lab_report_show_closing_identity', 'lab_report_show_footer', 'lab_report_show_footer_patient',
        'lab_report_show_page_numbers', 'lab_report_show_qr',
    ];

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * La valeur de chaque réglage telle que le formulaire des paramètres la montre :
     * un choix, un nombre ou un interrupteur jamais réglé prend la valeur d'origine ;
     * une couleur ou un texte vide reste vide (le champ montre alors ce qui s'applique).
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $raw = $this->stored();
        $values = [];

        foreach (self::COLORS as $field) {
            $values[$field] = self::color($raw[$field] ?? null);
        }

        foreach (array_keys(self::TEXTS) as $field) {
            $values[$field] = $raw[$field] ?? null;
        }

        foreach (array_keys(self::CHOICES) as $field) {
            $values[$field] = self::choice($raw, $field);
        }

        foreach (array_keys(self::NUMBERS) as $field) {
            $values[$field] = self::number($raw, $field);
        }

        foreach (array_keys(self::SWITCHES) as $field) {
            $values[$field] = self::switch($raw, $field);
        }

        return $values;
    }

    /**
     * Le compte rendu tel que ce site l'imprime. `$draft` : les réglages en cours de
     * saisie, pour l'aperçu des paramètres — rien n'est enregistré.
     *
     * @param  array<string, mixed>|null  $draft
     * @return array<string, mixed>
     */
    public function resolve(?array $draft = null): array
    {
        return self::build(
            $draft ?? $this->stored(),
            $this->settings->brand(),
            (string) config('rivo.site.name', ''),
            $this->settings->primaryColor(),
        );
    }

    /** Les valeurs d'origine, pour les champs vides du formulaire. @return array<string, string> */
    public function fallbacks(): array
    {
        $origin = $this->resolve([]);

        return [
            'accent' => $origin['accent'],
            'text' => $origin['text'],
            'heading' => $origin['heading'],
            'subheading' => $origin['subheading'],
            'lab_signatory' => $origin['lab_signatory'],
            'physician_signatory' => $origin['physician_signatory'],
            'footer_text' => $origin['footer_text'],
        ];
    }

    /**
     * Le compte rendu, lu sur des réglages bruts : la seule définition des valeurs
     * d'origine, que la vue reprend quand un compte rendu arrive sans réglage.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function build(array $values, string $brand, string $site, ?string $primary): array
    {
        $text = fn (string $field): ?string => self::filled($values[$field] ?? null);
        $place = $site !== '' ? ' — '.$site : '';

        return [
            'template' => self::choice($values, 'lab_report_template'),
            'font' => self::choice($values, 'lab_report_font'),
            'font_size' => self::number($values, 'lab_report_font_size'),
            'accent' => self::color($values['lab_report_accent_color'] ?? null) ?? self::color($primary) ?? self::DEFAULT_ACCENT,
            'text' => self::color($values['lab_report_text_color'] ?? null) ?? self::DEFAULT_TEXT,
            'section_background' => self::color($values['lab_report_section_background'] ?? null),
            'patient_background' => self::color($values['lab_report_patient_background'] ?? null),
            'abnormal' => self::color($values['lab_report_abnormal_color'] ?? null),
            'heading' => $text('lab_report_heading') ?? $brand,
            'subheading' => $text('lab_report_subheading') ?? self::DEFAULT_SUBHEADING.$place,
            'title' => $text('lab_report_title'),
            'signatory' => self::choice($values, 'lab_report_signatory'),
            'lab_signatory' => $text('lab_report_lab_signatory') ?? self::DEFAULT_LAB_SIGNATORY,
            'physician_signatory' => $text('lab_report_physician_signatory') ?? self::DEFAULT_PHYSICIAN_SIGNATORY,
            'footer_text' => $text('lab_report_footer_text') ?? $brand.$place,
            'website' => $text('lab_report_website'),
            'show_logo' => self::switch($values, 'lab_report_show_logo'),
            'show_contacts' => self::switch($values, 'lab_report_show_contacts'),
            'show_legal' => self::switch($values, 'lab_report_show_legal'),
            'show_anteriority' => self::switch($values, 'lab_report_show_anteriority'),
            'zebra' => self::switch($values, 'lab_report_zebra'),
            'show_sent' => self::switch($values, 'lab_report_show_sent'),
            'show_approval' => self::switch($values, 'lab_report_show_approval'),
            'show_generated' => self::switch($values, 'lab_report_show_generated'),
            'show_closing_identity' => self::switch($values, 'lab_report_show_closing_identity'),
            'show_footer' => self::switch($values, 'lab_report_show_footer'),
            'show_footer_patient' => self::switch($values, 'lab_report_show_footer_patient'),
            'show_page_numbers' => self::switch($values, 'lab_report_show_page_numbers'),
            'show_qr' => self::switch($values, 'lab_report_show_qr'),
        ];
    }

    /** Le texte lisible sur un fond : blanc sur un fond foncé, le noir d'origine sinon. */
    public static function inkOn(string $background): string
    {
        $color = self::color($background) ?? '#FFFFFF';
        [$red, $green, $blue] = sscanf($color, '#%02x%02x%02x');

        return (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255 < 0.6 ? '#FFFFFF' : self::DEFAULT_TEXT;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $setting = $this->settings->setting();

        return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => $setting?->{$field}])->all();
    }

    /** @param array<string, mixed> $values */
    private static function choice(array $values, string $field): string
    {
        $value = (string) ($values[$field] ?? '');

        return in_array($value, self::CHOICES[$field], true) ? $value : self::CHOICES[$field][0];
    }

    /** @param array<string, mixed> $values */
    private static function number(array $values, string $field): int
    {
        [$min, $max, $default] = self::NUMBERS[$field];
        $value = $values[$field] ?? null;

        return $value === null || $value === '' || ! is_numeric($value) ? $default : max($min, min($max, (int) $value));
    }

    /** @param array<string, mixed> $values */
    private static function switch(array $values, string $field): bool
    {
        $value = $values[$field] ?? null;

        return $value === null || $value === '' ? self::SWITCHES[$field] : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : null;
    }

    private static function filled(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
