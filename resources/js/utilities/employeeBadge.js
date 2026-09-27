import {
    Ambulance, Baby, BadgeCheck, BriefcaseBusiness, BriefcaseMedical, Car, FlaskConical, GraduationCap, HandHeart,
    HeartPulse, Hospital, IdCard, Microscope, Pill, Scissors, ShieldCheck, ShieldPlus, Shirt, SmilePlus, Sparkles,
    Sprout, Stethoscope, Store, Syringe, UserRound, UtensilsCrossed, Wrench,
} from 'lucide-vue-next';
import QRCode from 'qrcode';

/**
 * ADR-209 — le badge du personnel : ce que l'écran déduit, pour l'affichage
 * seulement, de ce que le serveur sert. Le même modèle pour tout le personnel.
 *
 * L'icône se choisit sur le **code** stable du référentiel (fonction, puis
 * département, puis filière d'un stage), jamais sur un libellé qui se renomme
 * (ADR-052). Une fonction sans icône prévue garde la carte d'identité.
 *
 * Amendement du 2026-09-27 : tout ce qui se règle sur le badge (couleurs, textes,
 * éléments affichés, icône, polices et tailles, disposition, impression). Les
 * valeurs de la clinique sont celles de `App\Support\Hr\BadgeDesign` : une seule
 * liste des deux côtés, vérifiée par test.
 */
export const BADGE_DEFAULT_DESIGN = Object.freeze({
    primary: '#1B4FA3',
    accent: '#F6C318',
    text: null,
    background: null,
    tagline: 'Votre santé, notre priorité',
    intern_label: 'Stagiaire',
    number_label: 'N°',
    footer_text: null,
    show_photo: true,
    show_tagline: true,
    show_icon: true,
    show_department: true,
    show_job: true,
    show_number: true,
    show_validity: true,
    show_site: false,
    show_watermark: true,
    show_decorations: true,
    show_qr: true,
    logo_style: 'SEAL',
    emblem_url: '/images/brand/clinic-saint-georges-emblem.png',
    brand: 'Clinique Saint Georges',
    site: '',
    seal: { top: 'CLINIQUE', bottom: 'SAINT GEORGES' },
    icon: 'AUTO',
    font: 'SANS',
    tagline_font: 'SCRIPT',
    name_case: 'UPPER',
    name_order: 'FIRST_LAST',
    text_case: 'UPPER',
    orientation: 'PORTRAIT',
    card_size: 'STANDARD',
    card_width: 105,
    card_height: 149,
    photo_shape: 'CIRCLE',
    corners: 'ROUNDED',
    name_size: 100,
    text_size: 100,
    tagline_size: 100,
    print: { paper: 'A4', orientation: 'PORTRAIT', margin: 8, gap: 4, cut_marks: true },
});

/** Format carte bancaire (ISO/CEI 7810 ID-1), en portrait : ce que les porte-badges reçoivent. */
export const BADGE_SIZE_MM = Object.freeze({ width: 54, height: 85.6 });

/* ------------------------------------------------------------------ */
/* Les réglages (Paramètres › Badge du personnel)                      */
/* ------------------------------------------------------------------ */

/** Chaque réglage du badge : la même liste que `BadgeDesign::FIELDS`. */
export const BADGE_FIELDS = Object.freeze([
    'badge_primary_color', 'badge_accent_color', 'badge_text_color', 'badge_background_color',
    'badge_tagline', 'badge_seal_top', 'badge_seal_bottom', 'badge_intern_label', 'badge_number_label', 'badge_footer_text',
    'badge_logo_style', 'badge_icon', 'badge_font', 'badge_tagline_font', 'badge_name_case', 'badge_name_order', 'badge_text_case',
    'badge_orientation', 'badge_card_size', 'badge_photo_shape', 'badge_corners', 'badge_paper', 'badge_paper_orientation',
    'badge_name_size', 'badge_text_size', 'badge_tagline_size', 'badge_page_margin', 'badge_gap',
    'badge_card_width', 'badge_card_height',
    'badge_show_photo', 'badge_show_tagline', 'badge_show_icon', 'badge_show_department', 'badge_show_job',
    'badge_show_number', 'badge_show_validity', 'badge_show_site', 'badge_show_watermark', 'badge_show_decorations',
    'badge_show_qr', 'badge_cut_marks',
]);

export const BADGE_COLOR_FIELDS = Object.freeze(['badge_primary_color', 'badge_accent_color', 'badge_text_color', 'badge_background_color']);

/** Les textes libres et leur longueur maximale (`BadgeDesign::TEXTS`). */
export const BADGE_TEXT_LIMITS = Object.freeze({
    badge_tagline: 80,
    badge_seal_top: 30,
    badge_seal_bottom: 40,
    badge_intern_label: 24,
    badge_number_label: 12,
    badge_footer_text: 60,
});

/** Les choix, la première valeur étant celle de la clinique (`BadgeDesign::CHOICES`). */
export const BADGE_CHOICES = Object.freeze({
    badge_logo_style: ['SEAL', 'LOGO', 'NONE'],
    badge_icon: [
        'AUTO', 'STETHOSCOPE', 'SYRINGE', 'HEART_PULSE', 'HOSPITAL', 'AMBULANCE', 'BRIEFCASE_MEDICAL',
        'SHIELD_PLUS', 'HAND_HEART', 'MICROSCOPE', 'PILL', 'BABY', 'BADGE_CHECK', 'ID_CARD', 'USER',
    ],
    badge_font: ['SANS', 'ARIAL', 'SERIF'],
    badge_tagline_font: ['SCRIPT', 'SANS', 'SERIF'],
    badge_name_case: ['UPPER', 'AS_IS'],
    badge_name_order: ['FIRST_LAST', 'LAST_FIRST'],
    badge_text_case: ['UPPER', 'AS_IS'],
    badge_orientation: ['PORTRAIT', 'LANDSCAPE'],
    badge_card_size: [
        'STANDARD', 'LARGE', 'XLARGE',
        'HOLDER_86X101', 'HOLDER_74X110', 'HOLDER_80X135', 'HOLDER_A6', 'HOLDER_110X152', 'HOLDER_108X155',
        'CUSTOM',
    ],
    badge_photo_shape: ['CIRCLE', 'ROUNDED'],
    badge_corners: ['ROUNDED', 'SQUARE'],
    badge_paper: ['A4', 'A5', 'A3', 'LETTER', 'CARD'],
    badge_paper_orientation: ['PORTRAIT', 'LANDSCAPE'],
});

/** Les nombres : [minimum, maximum, valeur de la clinique] (`BadgeDesign::NUMBERS`). */
export const BADGE_NUMBERS = Object.freeze({
    badge_name_size: [70, 150, 100],
    badge_text_size: [70, 150, 100],
    badge_tagline_size: [70, 150, 100],
    badge_page_margin: [0, 25, 8],
    badge_gap: [0, 20, 4],
    badge_card_width: [40, 160, 105],
    badge_card_height: [50, 230, 149],
});

/** Ce qui s'affiche, et sa valeur jamais réglée (`BadgeDesign::SWITCHES`). */
export const BADGE_SWITCHES = Object.freeze({
    badge_show_photo: true,
    badge_show_tagline: true,
    badge_show_icon: true,
    badge_show_department: true,
    badge_show_job: true,
    badge_show_number: true,
    badge_show_validity: true,
    badge_show_site: false,
    badge_show_watermark: true,
    badge_show_decorations: true,
    badge_show_qr: true,
    badge_cut_marks: true,
});

/**
 * La valeur d'un réglage du badge dans le formulaire : un choix, un nombre ou un
 * interrupteur jamais réglé prend celle de la clinique ; une couleur s'édite en
 * majuscules. La même valeur ne compte ainsi jamais deux fois comme modifiée.
 */
export const badgeFormValue = (field, value) => {
    if (BADGE_CHOICES[field]) return BADGE_CHOICES[field].includes(value) ? value : BADGE_CHOICES[field][0];
    if (BADGE_NUMBERS[field]) {
        const [min, max, fallback] = BADGE_NUMBERS[field];
        const number = Number(value);

        return value === null || value === undefined || value === '' || Number.isNaN(number) ? fallback : Math.max(min, Math.min(max, Math.round(number)));
    }
    if (field in BADGE_SWITCHES) {
        if (value === null || value === undefined || value === '') return BADGE_SWITCHES[field];

        return value !== false && value !== 0 && value !== '0';
    }
    if (BADGE_COLOR_FIELDS.includes(field)) return value ? String(value).toUpperCase() : '';

    return value ?? '';
};

/* ------------------------------------------------------------------ */
/* Ce que proposent les listes                                         */
/* ------------------------------------------------------------------ */

/** Les icônes fixes du médaillon, pour tout le personnel. `AUTO` : selon la fonction. */
export const BADGE_ICONS = Object.freeze({
    STETHOSCOPE: { label: 'Stéthoscope', icon: Stethoscope },
    SYRINGE: { label: 'Seringue', icon: Syringe },
    HEART_PULSE: { label: 'Cœur et pouls', icon: HeartPulse },
    HOSPITAL: { label: 'Hôpital', icon: Hospital },
    AMBULANCE: { label: 'Ambulance', icon: Ambulance },
    BRIEFCASE_MEDICAL: { label: 'Mallette médicale', icon: BriefcaseMedical },
    SHIELD_PLUS: { label: 'Bouclier et croix', icon: ShieldPlus },
    HAND_HEART: { label: 'Main et cœur', icon: HandHeart },
    MICROSCOPE: { label: 'Microscope', icon: Microscope },
    PILL: { label: 'Gélule', icon: Pill },
    BABY: { label: 'Bébé', icon: Baby },
    BADGE_CHECK: { label: 'Insigne validé', icon: BadgeCheck },
    ID_CARD: { label: 'Carte d’identité', icon: IdCard },
    USER: { label: 'Personne', icon: UserRound },
});

export const BADGE_FONTS = Object.freeze({
    SANS: { label: 'Moderne', hint: 'arrondie, sans empattement', family: "Nunito, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif" },
    ARIAL: { label: 'Classique', hint: 'type Arial', family: "Arial, Helvetica, 'Liberation Sans', sans-serif" },
    SERIF: { label: 'Avec empattements', hint: 'type Georgia', family: "Georgia, 'Times New Roman', 'Liberation Serif', serif" },
});

export const BADGE_TAGLINE_FONTS = Object.freeze({
    SCRIPT: { label: 'Manuscrite', hint: 'écrite à la main', family: "'Dancing Script', 'Segoe Script', cursive", italic: false },
    SANS: { label: 'Comme le texte', hint: 'la police du badge', family: null, italic: false },
    SERIF: { label: 'Italique', hint: 'avec empattements', family: "Georgia, 'Times New Roman', 'Liberation Serif', serif", italic: true },
});

/**
 * Chaque format, en portrait (côté court × côté long, mm) — `BadgeDesign::FORMATS_MM` :
 * la carte bancaire et ses agrandissements, puis l'insert des porte-badges souples
 * courants. `CUSTOM` : les deux côtés réglés.
 */
export const BADGE_FORMATS_MM = Object.freeze({
    STANDARD: [54, 85.6],
    LARGE: [62.1, 98.4],
    XLARGE: [70.2, 111.3],
    HOLDER_86X101: [86, 101],
    HOLDER_74X110: [74, 110],
    HOLDER_80X135: [80, 135],
    HOLDER_A6: [105, 149],
    HOLDER_110X152: [110, 152],
    HOLDER_108X155: [108, 155],
});

/** Le rapport côté long / côté court d'un format sur mesure (`BadgeDesign::PROPORTION`). */
export const BADGE_PROPORTION = Object.freeze([1.15, 1.8]);

/**
 * Ce que la liste des formats dit de chacun : son nom, ses dimensions telles qu'on
 * les achète (« 110 × 74 mm » pour un porte-badge horizontal), et le sens dans
 * lequel il se porte (`natural`), que le choix propose.
 */
export const BADGE_FORMATS = Object.freeze({
    STANDARD: { group: 'CARD', label: 'Carte bancaire (CR80)', sold: '54 × 85,6 mm', hint: 'porte-badge rigide ou souple 86 × 54', natural: null },
    LARGE: { group: 'CARD', label: 'Carte agrandie', sold: '62,1 × 98,4 mm', hint: 'le rapport de la carte, 15 % plus grande', natural: null },
    XLARGE: { group: 'CARD', label: 'Carte très agrandie', sold: '70,2 × 111,3 mm', hint: 'le rapport de la carte, 30 % plus grande', natural: null },
    HOLDER_86X101: { group: 'HOLDER', label: 'Porte-badge vertical', sold: '86 × 101 mm', hint: 'souple', natural: 'PORTRAIT' },
    HOLDER_74X110: { group: 'HOLDER', label: 'Porte-badge horizontal', sold: '110 × 74 mm', hint: 'souple', natural: 'LANDSCAPE' },
    HOLDER_80X135: { group: 'HOLDER', label: 'Porte-badge vertical allongé', sold: '80 × 135 mm', hint: 'souple', natural: 'PORTRAIT' },
    HOLDER_A6: { group: 'HOLDER', label: 'Porte-badge A6', sold: '105 × 149 mm', hint: 'souple, vertical, grand format', natural: 'PORTRAIT' },
    HOLDER_110X152: { group: 'HOLDER', label: 'Porte-badge XXL vertical', sold: '110 × 152 mm', hint: 'souple', natural: 'PORTRAIT' },
    HOLDER_108X155: { group: 'HOLDER', label: 'Porte-badge XXL horizontal', sold: '155 × 108 mm', hint: 'souple', natural: 'LANDSCAPE' },
    CUSTOM: { group: 'CUSTOM', label: 'Sur mesure', sold: null, hint: 'les dimensions de l’insert de votre porte-badge', natural: null },
});

/** Le nom court d'un format, pour un en-tête : « Porte-badge A6 · 105 × 149 mm ». */
export const badgeFormatName = (size, card) => {
    const format = BADGE_FORMATS[size] ?? BADGE_FORMATS.STANDARD;

    return `${format.label} · ${format.sold ?? formatMm(card)}`;
};

/** Les papiers, en portrait (mm). `CARD` : une carte par page, pour une imprimante à badges. */
export const BADGE_PAPERS_MM = Object.freeze({ A4: [210, 297], A5: [148, 210], A3: [297, 420], LETTER: [215.9, 279.4] });

export const BADGE_PAPER_LABELS = Object.freeze({ A4: 'A4', A5: 'A5', A3: 'A3', LETTER: 'Lettre US', CARD: 'Carte seule' });

/** Un millimètre sur l'écran, en pixels CSS. */
export const MM_TO_PX = 96 / 25.4;

const round1 = (value) => Math.round(value * 10) / 10;

const clampMm = (value, [min, max, fallback]) => {
    const number = Number(value);

    return value === null || value === undefined || value === '' || Number.isNaN(number) ? fallback : Math.max(min, Math.min(max, number));
};

/**
 * La carte en millimètres, dans son orientation — comme `BadgeDesign::cardMm()`.
 * « Sur mesure » : les deux côtés réglés (`custom`), rangés côté court × côté long.
 */
export const badgeCardMm = (size, orientation, custom = null) => {
    let [width, height] = BADGE_FORMATS_MM[size] ?? BADGE_FORMATS_MM.STANDARD;

    if (size === 'CUSTOM') {
        const a = clampMm(custom?.width, BADGE_NUMBERS.badge_card_width);
        const b = clampMm(custom?.height, BADGE_NUMBERS.badge_card_height);
        [width, height] = [Math.min(a, b), Math.max(a, b)];
    }

    width = round1(width);
    height = round1(height);

    return orientation === 'LANDSCAPE' ? { width: height, height: width } : { width, height };
};

/** La carte d'une apparence servie par le serveur (ou composée par les paramètres). */
export const badgeCardOf = (design = {}) => badgeCardMm(design?.card_size, design?.orientation, { width: design?.card_width, height: design?.card_height });

/** Le rapport côté long / côté court d'une carte. */
export const badgeProportion = ({ width, height }) => Math.max(width, height) / Math.max(1, Math.min(width, height));

/** « 54 × 85,6 mm » */
export const formatMm = ({ width, height }) => `${String(width).replace('.', ',')} × ${String(height).replace('.', ',')} mm`;

/**
 * La planche : combien de cartes par page, en colonnes et rangées, marges et
 * espacement compris — le même calcul que `BadgeDesign::perPage()`. Une carte
 * par page (« Carte seule ») : la page prend la taille de la carte.
 */
export const badgeSheetLayout = ({ paper = 'A4', paperOrientation = 'PORTRAIT', margin = 8, gap = 4, cardSize = 'STANDARD', orientation = 'PORTRAIT', custom = null } = {}) => {
    const card = badgeCardMm(cardSize, orientation, custom);

    if (! BADGE_PAPERS_MM[paper]) {
        return { mode: 'card', page: { ...card }, card, columns: 1, rows: 1, perPage: 1, margin: 0, gap: 0, fits: true };
    }

    let [width, height] = BADGE_PAPERS_MM[paper];
    if (paperOrientation === 'LANDSCAPE') [width, height] = [height, width];

    const columns = Math.max(0, Math.floor((width - 2 * margin + gap) / (card.width + gap)));
    const rows = Math.max(0, Math.floor((height - 2 * margin + gap) / (card.height + gap)));

    return { mode: 'sheet', page: { width, height }, card, columns, rows, perPage: columns * rows, margin, gap, fits: columns * rows > 0 };
};

/** Coupe une liste en pages de `size` éléments. */
export const chunk = (items, size) => {
    const pages = [];
    const step = Math.max(1, size);
    for (let index = 0; index < items.length; index += step) pages.push(items.slice(index, index + step));

    return pages;
};

/* ------------------------------------------------------------------ */
/* Ce que le badge montre d'une personne                               */
/* ------------------------------------------------------------------ */

const JOB_ICONS = Object.freeze({
    DOCTOR: Stethoscope,
    GENERAL_NURSE: Syringe,
    ANESTHETIST_NURSE: Syringe,
    OPERATING_ROOM_NURSE: Scissors,
    MIDWIFE: Baby,
    LAB_TECHNICIAN: FlaskConical,
    PHARMACIST: Pill,
    GUARD: ShieldCheck,
    HOUSEKEEPER: Sparkles,
    GARDENER: Sprout,
    DRIVER: Car,
    MAINTENANCE: Wrench,
    LAUNDRY: Shirt,
    WAITER: UtensilsCrossed,
    MANAGER: Store,
    ADMIN: BriefcaseBusiness,
    DENTIST: SmilePlus,
    DENTAL_ASSISTANT: SmilePlus,
});

const DEPARTMENT_ICONS = Object.freeze({
    MEDICINE: Stethoscope,
    SURGERY: Scissors,
    MATERNITY: Baby,
    LABORATORY: FlaskConical,
    PHARMACY: Pill,
    ADMINISTRATION: BriefcaseBusiness,
    SUPPORT: ShieldCheck,
    TSARASHOP: Store,
    DENTISTRY: SmilePlus,
});

const FIELD_ICONS = Object.freeze({
    NURSING: Syringe,
    MIDWIFERY: Baby,
    MEDICINE: Stethoscope,
    ANESTHESIA: HeartPulse,
    LABORATORY: FlaskConical,
    PHARMACY: Pill,
    ADMINISTRATION: BriefcaseBusiness,
});

/** L'icône du médaillon : celle réglée pour tout le monde, sinon celle de la fonction. */
export const badgeIcon = (person, design = {}) => BADGE_ICONS[design?.icon]?.icon
    ?? JOB_ICONS[person?.job_title_code]
    ?? DEPARTMENT_ICONS[person?.department_code]
    ?? (person?.is_intern ? (FIELD_ICONS[person?.internship?.field_code] ?? GraduationCap) : null)
    ?? IdCard;

/**
 * Ce que le badge écrit sous le nom : la pastille (le service) et la ligne
 * (la fonction). Un stagiaire porte « Stagiaire » (ou le libellé réglé) dans la
 * pastille, toujours, et sa filière dessous : on ne le prend jamais pour un
 * titulaire du poste. Masquer le service ou la fonction retire ce texte.
 */
export const badgeRole = (person, design = {}) => {
    const showDepartment = design?.show_department !== false;
    const showJob = design?.show_job !== false;

    if (person?.is_intern) {
        return {
            pill: String(design?.intern_label ?? '').trim() || BADGE_DEFAULT_DESIGN.intern_label,
            line: showJob ? (person.internship?.field || person.job_title || person.department || '') : '',
        };
    }

    const department = showDepartment ? (person?.department || '') : '';
    const job = showJob ? (person?.job_title || '') : '';

    if (! department && ! job) return { pill: showDepartment || showJob ? 'Personnel' : '', line: '' };

    return { pill: department || job, line: department ? job : '' };
};

/** La référence imprimée : celle saisie au dossier (« Badge »), sinon le matricule. */
export const badgeNumber = (person) => person?.badge_number || person?.employee_number || '';

/** Le prénom, et le nom de famille en majuscules (sauf si le site le garde tel qu'écrit). */
export const badgeNameLines = (person, design = {}) => {
    const upper = design?.name_case !== 'AS_IS';
    const first = String(person?.first_name ?? '').trim();
    const last = String(person?.last_name ?? '').trim();

    if (! first && ! last) {
        const name = String(person?.name ?? '').trim();

        return { first: '', last: upper ? name.toLocaleUpperCase('fr-FR') : name };
    }

    return { first, last: upper ? last.toLocaleUpperCase('fr-FR') : last };
};

/**
 * Les deux lignes du nom : le bandeau coloré et la grande ligne blanche. Par
 * défaut le prénom sur le bandeau, le nom dessous ; « Nom d'abord » les inverse.
 * Un nom réduit à une seule partie va dans la grande ligne, sans bandeau.
 */
export const badgeNameRows = (person, design = {}) => {
    const { first, last } = badgeNameLines(person, design);
    const [band, main] = design?.name_order === 'LAST_FIRST' ? [last, first] : [first, last];

    return band && main ? { band, main } : { band: '', main: band || main };
};

/** Le badge d'un stagiaire dit jusqu'à quand il vaut. */
export const internValidity = (person) => {
    const endsOn = person?.internship?.ends_on;

    if (! person?.is_intern || ! endsOn) return '';

    const date = new Date(`${endsOn}T00:00:00`);

    return Number.isNaN(date.getTime()) ? '' : `Valable jusqu’au ${new Intl.DateTimeFormat('fr-FR').format(date)}`;
};

/** Le pied du badge : le numéro, la fin du stage et la mention réglée, séparés d'un point. */
export const badgeFooter = (person, design = {}) => {
    const number = design?.show_number !== false ? badgeNumber(person) : '';
    const label = String(design?.number_label ?? '').trim() || BADGE_DEFAULT_DESIGN.number_label;

    return [
        number ? `${label} ${number}` : '',
        design?.show_validity !== false ? internValidity(person) : '',
        String(design?.footer_text ?? '').trim(),
    ].filter(Boolean).join('  ·  ');
};

/**
 * La taille d'un texte sur une seule ligne, en unités du badge : la plus grande
 * qui tient dans `width`. `ratio` est la largeur moyenne d'un caractère rapportée
 * à la taille de la police (≈ 0,62 pour des capitales grasses). Jamais sous `min` :
 * au-delà, le texte se resserre plutôt que de devenir illisible.
 */
export const fitFontSize = (text, { max, min, width, ratio = 0.6 }) => {
    const length = Math.max(1, [...String(text ?? '')].length);

    return Math.max(min, Math.min(max, width / (length * ratio)));
};

/**
 * Une ligne de texte réglée en taille (`percent`, 100 = la taille de la clinique)
 * sans jamais déborder : la taille est bornée par la hauteur de sa case (`cap`),
 * et un texte qui resterait trop long à la taille minimale est resserré à la
 * largeur exacte (`length`, pour `textLength`).
 */
export const fitLine = (text, { max, min, width, ratio = 0.6, percent = 100, cap = Infinity }) => {
    const factor = Math.max(0.5, Number(percent) || 100) / 100;
    const top = Math.min(max * factor, cap);
    const bottom = Math.min(min * Math.min(1, factor), top);
    const size = fitFontSize(text, { max: top, min: bottom, width, ratio });
    const estimated = [...String(text ?? '')].length * size * ratio;

    return { size, length: estimated > width ? width : null };
};

/** La devise sur deux lignes, coupée au mot le plus proche du milieu. */
export const splitTagline = (tagline) => {
    const text = String(tagline ?? '').trim();
    const words = text.split(/\s+/).filter(Boolean);

    if (words.length < 2) return text ? [text] : [];

    let best = 1;
    let bestGap = Infinity;
    for (let index = 1; index < words.length; index++) {
        const gap = Math.abs(words.slice(0, index).join(' ').length - words.slice(index).join(' ').length);
        if (gap < bestGap) { best = index; bestGap = gap; }
    }

    return [words.slice(0, best).join(' '), words.slice(best).join(' ')];
};

const channel = (hex, index) => parseInt(hex.slice(1 + index * 2, 3 + index * 2), 16);
export const isHex = (value) => /^#[0-9a-fA-F]{6}$/.test(String(value ?? ''));

/** Mélange une couleur avec une autre : `amount` = part de la seconde (0 à 1). */
export const mixHex = (hex, other, amount) => {
    const from = isHex(hex) ? hex : '#000000';
    const to = isHex(other) ? other : '#000000';
    const parts = [0, 1, 2].map((index) => Math.round(channel(from, index) * (1 - amount) + channel(to, index) * amount));

    return `#${parts.map((value) => value.toString(16).padStart(2, '0')).join('')}`.toUpperCase();
};

/**
 * Les teintes du badge : les deux couleurs réglées, le texte (réglé, sinon un
 * bleu foncé tiré de la principale) et le fond (réglé, sinon un blanc teinté).
 * Une couleur invalide retombe sur celle de la clinique.
 */
export const badgePalette = (design) => {
    const primary = isHex(design?.primary) ? design.primary.toUpperCase() : BADGE_DEFAULT_DESIGN.primary;
    const accent = isHex(design?.accent) ? design.accent.toUpperCase() : BADGE_DEFAULT_DESIGN.accent;
    const background = isHex(design?.background) ? design.background.toUpperCase() : null;

    return {
        primary,
        accent,
        ink: isHex(design?.text) ? design.text.toUpperCase() : mixHex(primary, '#000000', 0.45),
        light: mixHex(primary, '#FFFFFF', 0.9),
        paper: background ?? mixHex(primary, '#FFFFFF', 0.96),
        paperMiddle: background ?? '#FFFFFF',
        paperEnd: background ? mixHex(background, primary, 0.08) : mixHex(primary, '#FFFFFF', 0.9),
        accentLight: mixHex(accent, '#FFFFFF', 0.55),
    };
};

/* ------------------------------------------------------------------ */
/* La disposition de la carte                                          */
/* ------------------------------------------------------------------ */

/**
 * Où chaque élément se place, en unités du badge. Portrait : 540 × 856 (le
 * rapport 54 × 85,6) ; paysage : 856 × 540, la photo à gauche et le nom à droite.
 * Une seule table : le même composant dessine les deux.
 */
export const BADGE_GEOMETRY = Object.freeze({
    PORTRAIT: {
        width: 540,
        height: 856,
        radius: 26,
        bands: {
            primary: 'M0 0 H215 C150 45 70 100 0 150 Z',
            accent: 'M215 0 H268 C185 58 85 128 0 190 V150 C70 100 150 45 215 0 Z',
            line: 'M284 0 C200 62 96 134 0 206',
            pale: 'M300 0 H540 V70 C470 28 385 10 300 0 Z',
        },
        dots: { x: 22, y: 470 },
        watermark: { x: 340, y: 310, size: 290, cx: 500, cy: 450 },
        tagline: { cx: 135, y: 178, rotate: -9, width: 215, max: 32, min: 16 },
        seal: { cx: 412, cy: 112, scale: 1 },
        logo: { x: 262, y: 26, width: 256, height: 150 },
        site: { x: 412, y: 236, width: 230 },
        photo: { cx: 262, cy: 418, r: 140 },
        medallion: { cx: 432, cy: 486, r: 56 },
        name: {
            cx: 270,
            band: { x: 58, y: 546, width: 424, height: 56, textY: 575, fit: 380, max: 34, min: 17 },
            main: { x: 44, y: 594, width: 452, height: 64, textY: 627, fit: 410, max: 40, min: 17 },
            tabs: ['M34 580 L66 568 V650 L34 662 Z', 'M506 580 L474 568 V650 L506 662 Z'],
        },
        pill: { cx: 270, cy: 696, height: 42, maxWidth: 380, minWidth: 170, fit: 320, max: 25, min: 13, lineFrom: 60, lineTo: 480 },
        job: { cx: 270, ornamentY: 741, lines: [[150, 244], [296, 390]], y: 778, fit: 420, max: 24, min: 12 },
        wave: {
            fill: 'M0 808 C150 784 380 848 540 790 V856 H0 Z',
            line: 'M0 800 C160 772 380 836 540 778',
            cx: 270,
            y: 836,
            fit: 480,
            size: 17,
        },
    },
    LANDSCAPE: {
        width: 856,
        height: 540,
        radius: 26,
        bands: {
            primary: 'M0 0 H170 C118 36 56 80 0 118 Z',
            accent: 'M170 0 H212 C146 46 67 101 0 148 V118 C56 80 118 36 170 0 Z',
            line: 'M226 0 C158 49 76 106 0 160',
            pale: 'M600 0 H856 V60 C780 24 690 8 600 0 Z',
        },
        dots: { x: 788, y: 360 },
        watermark: { x: 590, y: 170, size: 260, cx: 830, cy: 330 },
        tagline: { cx: 450, y: 58, rotate: -5, width: 300, max: 30, min: 15 },
        seal: { cx: 772, cy: 96, scale: 0.78 },
        logo: { x: 612, y: 20, width: 224, height: 132 },
        site: { x: 772, y: 192, width: 160 },
        photo: { cx: 185, cy: 300, r: 124 },
        medallion: { cx: 300, cy: 400, r: 48 },
        name: {
            cx: 590,
            band: { x: 380, y: 206, width: 420, height: 50, textY: 232, fit: 380, max: 32, min: 16 },
            main: { x: 366, y: 250, width: 448, height: 62, textY: 282, fit: 410, max: 38, min: 16 },
            tabs: ['M342 232 L372 221 V298 L342 309 Z', 'M838 232 L808 221 V298 L838 309 Z'],
        },
        pill: { cx: 590, cy: 352, height: 40, maxWidth: 360, minWidth: 160, fit: 300, max: 24, min: 12, lineFrom: 384, lineTo: 796 },
        job: { cx: 590, ornamentY: 394, lines: [[470, 564], [616, 710]], y: 428, fit: 400, max: 23, min: 12 },
        wave: {
            fill: 'M0 492 C220 470 600 526 856 478 V540 H0 Z',
            line: 'M0 484 C230 460 600 516 856 468',
            cx: 428,
            y: 518,
            fit: 760,
            size: 17,
        },
    },
});

/*
 * La mise en page selon le format. La largeur ne change jamais (540 en portrait,
 * 856 en paysage) : la hauteur suit la proportion du format — 856 pour la carte
 * bancaire, 766 pour un porte-badge A6, 634 pour un 86 × 101. Trois zones :
 *
 *   l'en-tête   les bandes et la devise, collées au coin gauche ; l'établissement,
 *               collé au coin droit
 *   le milieu   la photo, le médaillon, le nom, le service et la fonction
 *   le pied     les vagues et le numéro, collés au bas de la carte
 *
 * Le milieu se centre dans la place qui reste, agrandi un peu s'il y en a (portrait)
 * ou réduit s'il en manque ; en portrait, quand même l'en-tête ne laisse plus assez
 * de place, en-tête et milieu se réduisent ensemble. Le QR code prend le coin bas
 * droit, sur les vagues : le milieu s'arrête au-dessus de lui.
 */
const BADGE_LAYOUT = Object.freeze({
    // top : où le milieu commence ; content : sa hauteur ; bottom : la place du pied ; grow : l'agrandissement
    // permis ; shrink : jusqu'où le milieu se resserre seul, avant que l'en-tête ne se réduise avec lui.
    PORTRAIT: { top: 248, content: 544, bottom: 64, grow: 1.1, shrink: 0.82 },
    // En paysage, le milieu occupe déjà toute la largeur : il ne s'agrandit pas, et l'en-tête ne bouge pas.
    LANDSCAPE: { top: 150, content: 295, bottom: 95, grow: 1, shrink: 0 },
});

/** Le QR code : un carré blanc de 116 unités (≈ 11,6 mm sur une carte bancaire), à 16 du bord. */
export const BADGE_QR = Object.freeze({ size: 116, margin: 16, padding: 10 });

const round2 = (value) => Math.round(value * 100) / 100;

/**
 * La disposition d'un badge : celle de `BADGE_GEOMETRY` pour son orientation, à la
 * hauteur de son format (`card`, en mm), avec la place du QR code (`qr`). Les
 * zones se déplacent par transformations : les coordonnées de chaque élément
 * restent celles du tableau.
 */
export const badgeGeometry = (orientation, card = null, { qr = false } = {}) => {
    const key = orientation === 'LANDSCAPE' ? 'LANDSCAPE' : 'PORTRAIT';
    const base = BADGE_GEOMETRY[key];
    const layout = BADGE_LAYOUT[key];
    const width = base.width;
    const ratio = card?.width > 0 && card?.height > 0 ? card.height / card.width : base.height / base.width;
    const height = Math.round(width * ratio);

    const tile = qr ? { size: BADGE_QR.size, x: width - BADGE_QR.margin - BADGE_QR.size, y: height - BADGE_QR.margin - BADGE_QR.size } : null;
    // Le milieu s'arrête au-dessus des vagues, ou du QR code.
    const limit = tile ? tile.y - 12 : height - layout.bottom;
    const room = limit - layout.top;

    let header = 1;
    let middle;
    let top;

    if (room >= layout.shrink * layout.content || key === 'LANDSCAPE') {
        middle = Math.min(layout.grow, room / layout.content);
        top = layout.top + (room - middle * layout.content) / 2;
    } else {
        // Portrait trop court : l'en-tête et le milieu se partagent la hauteur, à la même échelle.
        header = limit / (layout.top + layout.content);
        middle = header;
        top = layout.top * header;
    }

    header = round2(header);
    middle = round2(middle);
    const cx = width / 2;
    const dy = height - base.height;

    // Le numéro, dans les vagues : à gauche du QR code quand il y en a un.
    const wave = tile
        ? { ...base.wave, cx: round2((BADGE_QR.margin + tile.x - 12) / 2), fit: tile.x - 12 - 2 * BADGE_QR.margin }
        : base.wave;

    return {
        ...base,
        width,
        height,
        wave,
        qr: tile,
        layout: { header, middle, top: round2(top), from: layout.top, cx },
        transforms: {
            headerLeft: header === 1 ? null : `scale(${header})`,
            headerRight: header === 1 ? null : `translate(${round2(width * (1 - header))} 0) scale(${header})`,
            middle: middle === 1 && round2(top) === layout.top ? null : `translate(${round2(cx * (1 - middle))} ${round2(top - middle * layout.top)}) scale(${middle})`,
            footer: dy === 0 ? null : `translate(0 ${dy})`,
        },
    };
};

/**
 * Où un point du milieu se retrouve sur la carte (pour vérifier qu'il y reste).
 * `x` et `y` sont les coordonnées du tableau.
 */
export const badgeMiddlePoint = (geometry, x, y) => {
    const { middle, top, from, cx } = geometry.layout;

    return { x: cx + middle * (x - cx), y: top + middle * (y - from) };
};

/**
 * Le QR code du numéro imprimé — et de rien d'autre : un chemin SVG, en modules
 * (`size` × `size`), prêt à dessiner à n'importe quelle taille. Calculé sans
 * attendre, donc aussi au rendu serveur. `null` sans numéro.
 */
export const badgeQr = (text) => {
    const value = String(text ?? '').trim();
    if (! value) return null;

    try {
        const { modules } = QRCode.create(value, { errorCorrectionLevel: 'M' });
        const size = modules.size;
        let path = '';

        // Une ligne à la fois, les modules noirs voisins réunis en un seul rectangle.
        for (let y = 0; y < size; y++) {
            let run = 0;
            for (let x = 0; x <= size; x++) {
                const dark = x < size && modules.data[y * size + x];
                if (dark) {
                    run++;
                } else if (run > 0) {
                    path += `M${x - run} ${y}h${run}v1h-${run}z`;
                    run = 0;
                }
            }
        }

        return { size, path, text: value };
    } catch {
        return null;
    }
};

/** La petite croix au milieu des traits de la fonction. */
export const crossPath = (cx, cy) => `M${cx - 6} ${cy - 12} H${cx + 6} V${cy - 6} H${cx + 12} V${cy + 6} H${cx + 6} V${cy + 12} H${cx - 6} V${cy + 6} H${cx - 12} V${cy - 6} H${cx - 6} Z`;

/** Un arc du sceau : le dessus (texte lu de gauche à droite en haut) ou le dessous. */
export const sealArc = (cx, cy, radius, overTop) => (overTop
    ? `M ${cx - radius} ${cy} A ${radius} ${radius} 0 0 1 ${cx + radius} ${cy}`
    : `M ${cx - radius} ${cy} A ${radius} ${radius} 0 0 0 ${cx + radius} ${cy}`);

/* ------------------------------------------------------------------ */
/* Liens, sceau et aperçu                                              */
/* ------------------------------------------------------------------ */

/**
 * L'adresse de la planche de badges : les dossiers cochés s'il y en a, sinon
 * tous ceux que la liste affiche avec ses filtres (état, recherche, filière) —
 * jamais seulement la page ouverte. Sans dépendance, pour être testée seule ;
 * l'écran la passe ensuite par `hrUrl` (portail).
 */
export const badgeSheetPath = ({ scope = 'employees', uuids = [], status, q, field } = {}) => {
    const params = new URLSearchParams();

    if (uuids.length > 0) {
        uuids.forEach((uuid) => params.append('uuids[]', uuid));
    } else {
        if (status) params.set('status', status);
        if (q) params.set('q', q);
        if (field) params.set('field', field);
    }

    const list = scope === 'interns' ? 'internships' : 'employees';
    const query = params.toString();

    return `/administration/${list}/badges${query ? `?${query}` : ''}`;
};

/**
 * Le nom de l'établissement autour du sceau : le premier mot en haut, le reste en
 * bas — la même règle que `BadgeDesign::sealText()` côté serveur, pour l'aperçu
 * des paramètres avant d'enregistrer.
 */
export const sealText = (brand) => {
    const words = String(brand ?? '').trim().split(/\s+/).filter(Boolean);
    const top = words.shift() ?? '';

    return { top: top.toLocaleUpperCase('fr-FR'), bottom: words.join(' ').toLocaleUpperCase('fr-FR') };
};

/** Le texte du sceau réglé (l'une ou l'autre ligne), sinon le nom coupé — comme `BadgeDesign::seal()`. */
export const badgeSeal = (brand, top, bottom) => {
    const up = String(top ?? '').trim();
    const down = String(bottom ?? '').trim();

    return up || down
        ? { top: up.toLocaleUpperCase('fr-FR'), bottom: down.toLocaleUpperCase('fr-FR') }
        : sealText(brand);
};

/**
 * L'apparence que servirait `BadgeDesign::toArray()` pour ces valeurs du
 * formulaire : l'aperçu des paramètres, avant d'enregistrer.
 */
export const badgeDesignFromSettings = (form, { defaults = {}, brand = '', site = '', emblemUrl = '' } = {}) => {
    const base = { ...BADGE_DEFAULT_DESIGN, ...defaults };
    const value = (field) => badgeFormValue(field, form?.[field]);
    const text = (field, fallback) => String(form?.[field] ?? '').trim() || fallback;

    return {
        primary: isHex(form?.badge_primary_color) ? form.badge_primary_color.toUpperCase() : base.primary,
        accent: isHex(form?.badge_accent_color) ? form.badge_accent_color.toUpperCase() : base.accent,
        text: isHex(form?.badge_text_color) ? form.badge_text_color.toUpperCase() : null,
        background: isHex(form?.badge_background_color) ? form.badge_background_color.toUpperCase() : null,
        tagline: text('badge_tagline', base.tagline),
        intern_label: text('badge_intern_label', base.intern_label),
        number_label: text('badge_number_label', base.number_label),
        footer_text: text('badge_footer_text', null),
        logo_style: value('badge_logo_style'),
        emblem_url: emblemUrl || base.emblem_url,
        brand,
        site,
        seal: badgeSeal(brand, form?.badge_seal_top, form?.badge_seal_bottom),
        icon: value('badge_icon'),
        font: value('badge_font'),
        tagline_font: value('badge_tagline_font'),
        name_case: value('badge_name_case'),
        name_order: value('badge_name_order'),
        text_case: value('badge_text_case'),
        orientation: value('badge_orientation'),
        card_size: value('badge_card_size'),
        card_width: value('badge_card_width'),
        card_height: value('badge_card_height'),
        photo_shape: value('badge_photo_shape'),
        corners: value('badge_corners'),
        name_size: value('badge_name_size'),
        text_size: value('badge_text_size'),
        tagline_size: value('badge_tagline_size'),
        show_photo: value('badge_show_photo'),
        show_tagline: value('badge_show_tagline'),
        show_icon: value('badge_show_icon'),
        show_department: value('badge_show_department'),
        show_job: value('badge_show_job'),
        show_number: value('badge_show_number'),
        show_validity: value('badge_show_validity'),
        show_site: value('badge_show_site'),
        show_watermark: value('badge_show_watermark'),
        show_decorations: value('badge_show_decorations'),
        show_qr: value('badge_show_qr'),
        print: {
            paper: value('badge_paper'),
            orientation: value('badge_paper_orientation'),
            margin: value('badge_page_margin'),
            gap: value('badge_gap'),
            cut_marks: value('badge_cut_marks'),
        },
    };
};

/** Deux personnes fictives pour l'aperçu des paramètres : un employé, un stagiaire. */
export const BADGE_SAMPLES = Object.freeze({
    employee: Object.freeze({
        uuid: 'apercu-employe', first_name: 'Hanitra', last_name: 'Rakotoarisoa', name: 'Hanitra RAKOTOARISOA',
        photo_url: null, employee_number: 'EMP-0001', badge_number: null,
        department: 'Médecine', job_title: 'Médecin', department_code: 'MEDICINE', job_title_code: 'DOCTOR',
        is_intern: false, internship: null, active: true,
    }),
    intern: Object.freeze({
        uuid: 'apercu-stagiaire', first_name: 'Tojo', last_name: 'Andrianasolo', name: 'Tojo ANDRIANASOLO',
        photo_url: null, employee_number: 'EMP-0042', badge_number: null,
        department: 'Maternité', job_title: null, department_code: 'MATERNITY', job_title_code: null,
        is_intern: true, internship: Object.freeze({ field: 'Sage-femme', field_code: 'MIDWIFERY', ends_on: '2026-12-31' }), active: true,
    }),
});
