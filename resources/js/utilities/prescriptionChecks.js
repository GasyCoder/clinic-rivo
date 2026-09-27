import { DAYS_PER_DURATION_UNIT, INTAKES_PER_DAY, isUndosedForm } from './posology.js';

/**
 * Ce que le système peut relire d'une ligne d'ordonnance — et ce qu'il ne peut
 * pas (ADR-128).
 *
 * Tout ce qui suit se déduit de ce que l'application **sait déjà** : la forme
 * du produit, son dosage, la voie choisie, la posologie saisie, l'âge, le
 * poids, les allergies. Rien n'est une règle de médecine.
 *
 * Ce qui n'y est pas, et pourquoi : « cette dose est trop élevée pour cet
 * âge ». Ni le CDC ni le référentiel Pharmacie ne portent de dose maximale
 * (aucune dose par âge ou par poids), et en écrire dans le code serait
 * inventer de la médecine. Le système le dit — il ne prétend pas avoir
 * vérifié ce qu'il ne peut pas vérifier (voir `DOSE_LIMIT_NOTICE`).
 *
 * Écrit une seule fois : l'éditeur de ligne et la fenêtre de confirmation
 * lisent les mêmes alertes.
 */

/** Niveaux, du plus grave au moins grave. */
export const LEVELS = ['danger', 'warning', 'info'];

const PARENTERAL_ROUTES = ['IV', 'IM', 'SC'];
const NON_PARENTERAL_ROUTES = ['ORAL', 'RECTAL', 'VAGINAL', 'TOPICAL', 'OPHTHALMIC', 'NASAL', 'INHALED'];
/** Formes qui ne s'injectent pas : une voie IV/IM/SC y est une erreur de choix. */
const NON_INJECTABLE_FORMS = ['TABLET', 'SACHET', 'SYRUP'];

const ROUTE_NAMES = {
    ORAL: 'orale',
    IV: 'intraveineuse',
    IM: 'intramusculaire',
    SC: 'sous-cutanée',
    RECTAL: 'rectale',
    VAGINAL: 'vaginale',
    TOPICAL: 'cutanée / locale',
    OPHTHALMIC: 'ophtalmique',
    NASAL: 'nasale',
    INHALED: 'inhalée',
};

/** Ce que le système ne peut pas juger, dit sans détour. */
export const DOSE_LIMIT_NOTICE = 'Aucune dose maximale n’est enregistrée pour ce produit : le système ne peut pas dire si elle est trop élevée.';

const number = (value) => {
    const parsed = Number(String(value ?? '').trim().replace(',', '.'));

    return Number.isFinite(parsed) ? parsed : null;
};

/** « 62,5 » — une décimale au plus, virgule française. */
const spell = (value) => String(Math.round(value * 10) / 10).replace('.', ',');

/**
 * « 1 g » → `{ amount: 1000, dimension: 'mass' }` (en mg) ; « 10 ml » →
 * `{ amount: 10, dimension: 'volume' }`.
 *
 * Seul un dosage simple — un nombre et une unité — est lu. « 250 mg/5 ml »,
 * « 1 % » ou du texte libre rendent `null` : convertir ce qu'on ne comprend
 * pas donnerait un contrôle faux, pire que pas de contrôle.
 */
export const parseAmount = (text) => {
    const match = String(text ?? '').trim().match(/^(\d+(?:[.,]\d+)?)\s*(mg|g|µg|mcg|ml|ui)$/i);

    if (!match) {
        return null;
    }

    const amount = number(match[1]);
    const unit = match[2].toLowerCase();

    if (amount === null || amount <= 0) {
        return null;
    }

    if (unit === 'g') return { amount: amount * 1000, dimension: 'mass' };
    if (unit === 'mg') return { amount, dimension: 'mass' };
    if (unit === 'µg' || unit === 'mcg') return { amount: amount / 1000, dimension: 'mass' };
    if (unit === 'ml') return { amount, dimension: 'volume' };

    return { amount, dimension: 'international-units' };
};

const intakesPerDay = (frequency) => INTAKES_PER_DAY[String(frequency ?? '').trim()] ?? null;

const durationInDays = (line) => {
    const unit = line._duration_unit ?? 'jours';

    if (unit === 'prise unique') {
        return 1;
    }

    const amount = number(line._duration_amount);
    const factor = DAYS_PER_DURATION_UNIT[unit];

    return amount !== null && amount > 0 && factor ? amount * factor : null;
};

/** Plus petit mot qui suffit à comparer un nom à une allergie, sans accents. */
const normalize = (text) => String(text ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();

const mentions = (corpus, needle) => {
    const target = normalize(needle);

    return target !== '' && ` ${normalize(corpus)} `.includes(` ${target} `);
};

/**
 * Une allergie connue recoupée par un nom saisi à la main.
 *
 * Même règle que le serveur pour les propositions et le catalogue — mots
 * entiers, sans accents, dans les deux sens. Elle ne sert qu'aux lignes hors
 * référentiel : celles du catalogue arrivent déjà rapprochées par le serveur.
 */
export const allergyOverlap = (allergies, name) => (allergies ?? []).find(
    (substance) => mentions(name, substance) || mentions(substance, name),
) ?? null;

/**
 * @typedef {{ code: string, level: 'danger'|'warning'|'info', message: string }} PrescriptionAlert
 *
 * @param {object} args
 * @param {object} args.line       la ligne en préparation
 * @param {?object} args.medicine  la fiche du catalogue, `null` pour une ligne manuelle
 * @param {{ age: ?number, weightKg: ?number|undefined, allergyConflict: ?string, allergies: string[] }} args.patient
 * @returns {PrescriptionAlert[]}
 */
export const checkLine = ({ line, medicine, patient }) => {
    /** @type {PrescriptionAlert[]} */
    const alerts = [];
    const form = medicine?.form ?? null;
    const route = line.route || null;
    const dosed = line.manual || !isUndosedForm(form);
    const push = (code, level, message) => alerts.push({ code, level, message });

    // Allergie : le rapprochement du catalogue vient du serveur ; une ligne
    // manuelle est comparée ici, sur le nom saisi.
    const allergy = line.manual
        ? allergyOverlap(patient.allergies, line.medication_name)
        : patient.allergyConflict;

    if (allergy) {
        push('allergy', 'danger', `Allergie connue : ${allergy}. Ce produit la recoupe.`);
    }

    // Voie et forme : le produit lui-même dit comment il se donne.
    if (form === 'INJECTABLE' && NON_PARENTERAL_ROUTES.includes(route)) {
        push('route-form', 'danger', `Produit injectable prescrit par voie ${ROUTE_NAMES[route]} : la voie ne correspond pas à la forme.`);
    } else if (NON_INJECTABLE_FORMS.includes(form) && PARENTERAL_ROUTES.includes(route)) {
        push('route-form', 'danger', `${medicine.form_label} prescrit par voie ${ROUTE_NAMES[route]} : la voie ne correspond pas à la forme.`);
    } else if (form === 'INJECTABLE' && !route) {
        push('route-missing', 'warning', 'Produit injectable sans voie précisée (IV, IM ou SC) : la personne qui l’administre devra deviner.');
    }

    if (!dosed) {
        return sortAlerts(alerts);
    }

    // Une fréquence sans unité ne dit pas quoi compter : « 2 » sur l'écran.
    const frequency = String(line.frequency ?? '').trim();

    if (/^\d+$/.test(frequency)) {
        push('frequency', 'warning', `Fréquence « ${frequency} » incomplète : ${frequency} fois par jour ? Toutes les ${frequency} heures ? Choisissez « ${frequency} fois/jour » ou précisez.`);
    }

    const dose = parseAmount(line.dosage);
    const strength = parseAmount(medicine?.strength);
    const perDay = intakesPerDay(line.frequency);
    const days = durationInDays(line);

    // Quantité : ce que le traitement consomme, calculé à partir du dosage du
    // produit — de l'arithmétique, pas de la médecine.
    if (dose && strength && dose.dimension === strength.dimension && perDay && days) {
        const unitsPerIntake = dose.amount / strength.amount;
        const needed = Math.ceil(unitsPerIntake * perDay * days);
        const quantity = number(line.quantity);
        const perIntake = unitsPerIntake === 1 ? '1' : spell(unitsPerIntake);

        if (quantity !== null && quantity > 0 && quantity < needed) {
            push(
                'quantity',
                'warning',
                `Quantité insuffisante : ${perIntake} ${medicine.unit ?? 'unité'} par prise × ${perDay}/jour × ${spell(days)} jour${days > 1 ? 's' : ''} = ${needed}. Vous en prescrivez ${quantity}.`,
            );
        }
    }

    // Un enfant : la dose se rapporte au poids, et le système dit ce qu'il
    // peut — le rapport calculé — et ce qu'il ne peut pas.
    const minor = patient.age !== null && patient.age !== undefined && patient.age < 18;

    if (minor) {
        // `null` = le serveur dit que le poids n'a pas été relevé ; `undefined` =
        // le contexte n'est pas arrivé (page ouverte avant une mise à jour,
        // droit manquant). Affirmer « non relevé » dans ce second cas serait
        // faux : le système se tait plutôt que de contredire le dossier.
        if (patient.weightKg === null) {
            push('weight', 'warning', `Patient de ${patient.age} ans, poids non relevé : la dose d’un enfant se calcule au poids.`);
        } else if (patient.weightKg !== undefined && dose && dose.dimension === 'mass') {
            const perKg = dose.amount / patient.weightKg;
            const daily = perDay ? ` · ${spell(perKg * perDay)} mg/kg/jour` : '';

            push(
                'dose-per-kg',
                'info',
                `Patient de ${patient.age} ans, ${spell(patient.weightKg)} kg : ${spell(perKg)} mg/kg par prise${daily}. ${DOSE_LIMIT_NOTICE}`,
            );
        } else {
            push('dose-limit', 'info', `Patient de ${patient.age} ans. ${DOSE_LIMIT_NOTICE}`);
        }
    }

    return sortAlerts(alerts);
};

const sortAlerts = (alerts) => [...alerts].sort((a, b) => LEVELS.indexOf(a.level) - LEVELS.indexOf(b.level));

/**
 * Ce qui se voit d'une ordonnance entière, pas d'une ligne : le même principe
 * actif deux fois.
 *
 * @returns {Record<number, PrescriptionAlert[]>} alertes par position de ligne
 */
export const checkDuplicates = ({ lines, medicineFor }) => {
    const seen = new Map();
    const result = {};

    lines.forEach((line, index) => {
        if (line.manual) {
            return;
        }

        const medicine = medicineFor(line);
        const key = normalize(medicine?.generic_name);

        if (key === '') {
            return;
        }

        if (seen.has(key)) {
            const first = seen.get(key);
            const alert = {
                code: 'duplicate',
                level: 'warning',
                message: `Même principe actif que la ligne ${first + 1} (${medicine.generic_name}) : double prescription ?`,
            };

            result[index] = [...(result[index] ?? []), alert];
        } else {
            seen.set(key, index);
        }
    });

    return result;
};

/** Le niveau le plus grave d'une liste d'alertes, ou `null`. */
export const worstLevel = (alerts) => LEVELS.find((level) => alerts.some((alert) => alert.level === level)) ?? null;
