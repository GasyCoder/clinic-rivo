/**
 * ADR-223 — les réglages du compte rendu d'analyses (le PDF de l'ADR-218), tels que
 * les paramètres du portail les éditent. La même liste que
 * `App\Support\Laboratory\LabReportDesign` (vérifié par test) : un champ oublié ici
 * ne s'enregistrerait jamais, un champ en trop serait refusé.
 */

/** Chaque réglage, dans l'ordre de `LabReportDesign::FIELDS`. */
export const LAB_REPORT_FIELDS = Object.freeze([
    'lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background',
    'lab_report_abnormal_color', 'lab_report_heading', 'lab_report_subheading', 'lab_report_title', 'lab_report_lab_signatory',
    'lab_report_physician_signatory', 'lab_report_footer_text', 'lab_report_website',
    'lab_report_template', 'lab_report_font', 'lab_report_signatory',
    'lab_report_font_size',
    'lab_report_show_logo', 'lab_report_show_contacts', 'lab_report_show_legal', 'lab_report_show_anteriority',
    'lab_report_zebra', 'lab_report_show_sent', 'lab_report_show_approval', 'lab_report_show_generated',
    'lab_report_show_closing_identity', 'lab_report_show_footer', 'lab_report_show_footer_patient',
    'lab_report_show_page_numbers', 'lab_report_show_qr',
]);

/** Les couleurs (`LabReportDesign::COLORS`). Vide : la couleur d'origine. */
export const LAB_REPORT_COLOR_FIELDS = Object.freeze([
    'lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background', 'lab_report_abnormal_color',
]);

/** Les textes libres et leur longueur maximale (`LabReportDesign::TEXTS`). */
export const LAB_REPORT_TEXT_LIMITS = Object.freeze({
    lab_report_heading: 80,
    lab_report_subheading: 120,
    lab_report_title: 80,
    lab_report_lab_signatory: 60,
    lab_report_physician_signatory: 60,
    lab_report_footer_text: 120,
    lab_report_website: 120,
});

/** Les choix, la première valeur étant celle d'origine (`LabReportDesign::CHOICES`). */
export const LAB_REPORT_CHOICES = Object.freeze({
    lab_report_template: ['CLASSIC', 'BANNER', 'MINIMAL'],
    lab_report_font: ['SANS', 'SERIF'],
    lab_report_signatory: ['LAB', 'PHYSICIAN', 'BOTH', 'AUTO'],
});

/** Les nombres : [minimum, maximum, valeur d'origine] (`LabReportDesign::NUMBERS`). */
export const LAB_REPORT_NUMBERS = Object.freeze({
    lab_report_font_size: [90, 120, 100],
});

/** Ce qui s'affiche, et sa valeur jamais réglée (`LabReportDesign::SWITCHES`). */
export const LAB_REPORT_SWITCHES = Object.freeze({
    lab_report_show_logo: true,
    lab_report_show_contacts: true,
    lab_report_show_legal: true,
    lab_report_show_anteriority: true,
    lab_report_zebra: false,
    lab_report_show_sent: true,
    lab_report_show_approval: true,
    lab_report_show_generated: true,
    lab_report_show_closing_identity: true,
    lab_report_show_footer: true,
    lab_report_show_footer_patient: true,
    lab_report_show_page_numbers: true,
    lab_report_show_qr: false,
});

/**
 * La valeur d'un réglage dans le formulaire : un choix, un nombre ou un
 * interrupteur jamais réglé prend la valeur d'origine ; une couleur s'édite en
 * majuscules. La même valeur ne compte ainsi jamais deux fois comme modifiée.
 */
export const labReportFormValue = (field, value) => {
    if (LAB_REPORT_CHOICES[field]) return LAB_REPORT_CHOICES[field].includes(value) ? value : LAB_REPORT_CHOICES[field][0];
    if (LAB_REPORT_NUMBERS[field]) {
        const [min, max, fallback] = LAB_REPORT_NUMBERS[field];
        const number = Number(value);

        return value === null || value === undefined || value === '' || Number.isNaN(number) ? fallback : Math.max(min, Math.min(max, Math.round(number)));
    }
    if (field in LAB_REPORT_SWITCHES) {
        if (value === null || value === undefined || value === '') return LAB_REPORT_SWITCHES[field];

        return value !== false && value !== 0 && value !== '0';
    }
    if (LAB_REPORT_COLOR_FIELDS.includes(field)) return value ? String(value).toUpperCase() : '';

    return value ?? '';
};

/**
 * Les réglages envoyés à l'aperçu (une lecture, en GET) : un interrupteur part en
 * 1 / 0, un texte ou une couleur vide ne part pas (le PDF prend alors la valeur
 * d'origine, comme à l'enregistrement).
 */
export const labReportPreviewQuery = (form) => {
    const query = {};

    for (const field of LAB_REPORT_FIELDS) {
        const value = form?.[field];

        if (field in LAB_REPORT_SWITCHES) query[field] = labReportFormValue(field, value) ? '1' : '0';
        else if (value !== null && value !== undefined && String(value).trim() !== '') query[field] = String(value).trim();
    }

    return query;
};

/** Les modèles proposés. */
export const LAB_REPORT_TEMPLATES = Object.freeze({
    CLASSIC: { label: 'Classique', hint: 'Logo à gauche, l’établissement à droite, filet de couleur.' },
    BANNER: { label: 'Bandeau', hint: 'L’établissement sur un bandeau de la couleur principale.' },
    MINIMAL: { label: 'Sobre', hint: 'Centré, filets gris, peu d’encre de couleur.' },
});

/** Qui signe le compte rendu. */
export const LAB_REPORT_SIGNATORIES = Object.freeze({
    LAB: { label: 'Le laboratoire', hint: 'Une signature : « Le responsable du laboratoire ».' },
    PHYSICIAN: { label: 'Le médecin', hint: 'Une signature : le médecin qui a validé les résultats.' },
    BOTH: { label: 'Les deux', hint: 'Deux signatures côte à côte : le laboratoire et le médecin.' },
    AUTO: { label: 'Automatique', hint: 'Le médecin qui a demandé l’analyse ; sans médecin (demande de l’accueil), le laboratoire.' },
});
