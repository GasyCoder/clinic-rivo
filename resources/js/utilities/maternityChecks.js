/**
 * Ce que le dossier Maternité rappelle sous un champ pendant la saisie (ADR-137).
 *
 * Une **aide au dépistage**, jamais un diagnostic ni un verrou : chaque message
 * dit ce qui mérite un second regard — une unité mal comprise, une valeur
 * improbable, un repère hors norme —, et rien n'empêche d'enregistrer.
 *
 * Les seuils viennent tous du serveur (`App\Support\MaternityReference`) :
 * aucun chiffre n'est écrit ici, si bien qu'un repère corrigé l'est partout.
 * Ces fonctions ne font que lire une valeur, une référence et, pour les dates,
 * l'instant présent (passé en paramètre pour que le calcul reste testable).
 *
 * Un champ vide ne dit rien : une absence de saisie n'est ni normale ni
 * anormale (ADR-077).
 *
 * @typedef {{ code: string, level: 'danger'|'warning'|'info', message: string, action?: { label: string, value: string|number } }} Hint
 */

const DAY_MS = 86_400_000;

/** @returns {number|null} */
const number = (value) => {
    const parsed = Number(String(value ?? '').trim().replace(',', '.'));

    return String(value ?? '').trim() !== '' && Number.isFinite(parsed) ? parsed : null;
};

const grouped = (value) => new Intl.NumberFormat('fr-FR').format(value).replace(/[\u00a0\u202f]/g, ' ');

/** 3200 → « 3,2 kg », sans zéros inutiles. */
const kilograms = (grams) => `${(grams / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 3 })} kg`;

/** `YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm`, lu comme une date de calendrier sans fuseau. */
const calendarDay = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));

    return match ? Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
};

const toDay = (date) => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
const isoDate = (utcMs) => new Date(utcMs).toISOString().slice(0, 10);
const frenchDate = (utcMs) => new Date(utcMs).toLocaleDateString('fr-FR', { timeZone: 'UTC' });

/** Un instant `datetime-local` lu comme heure locale, comparé à `now`. */
const localInstant = (value) => {
    const parsed = new Date(String(value ?? ''));

    return String(value ?? '') !== '' && ! Number.isNaN(parsed.getTime()) ? parsed.getTime() : null;
};

/** @returns {Hint[]} */
export const birthWeightHints = (value, ref) => {
    const grams = number(value);

    if (grams === null) return [];

    const w = ref.birth_weight;

    // L'unité est la première source d'erreur : « 3,2 » ou « 10 » pour des kilos.
    if (grams < w.min) {
        return [{
            code: 'weight-unit',
            level: 'warning',
            message: `Le poids se saisit en grammes : ${grouped(3200)} pour 3,2 kg. ${grouped(grams)} g est en dessous de ${grouped(w.min)} g, le minimum accepté.`,
        }];
    }

    if (grams > w.max) {
        return [{
            code: 'weight-too-high',
            level: 'danger',
            message: `Plus de ${grouped(w.max)} g (${kilograms(w.max)}) : le dossier serait refusé. Vérifiez l’unité — le poids se saisit en grammes.`,
        }];
    }

    /** @type {Hint[]} */
    const hints = [{ code: 'weight-kg', level: 'info', message: `${grouped(grams)} g = ${kilograms(grams)}` }];

    if (grams < w.extremely_low) {
        hints.push({ code: 'weight-extremely-low', level: 'danger', message: `Poids extrêmement faible (< ${grouped(w.extremely_low)} g) : à signaler au médecin.` });
    } else if (grams < w.very_low) {
        hints.push({ code: 'weight-very-low', level: 'danger', message: `Poids très faible (< ${grouped(w.very_low)} g) : à signaler au médecin.` });
    } else if (grams < w.low) {
        hints.push({ code: 'weight-low', level: 'warning', message: `Faible poids de naissance (< ${grouped(w.low)} g).` });
    } else if (grams >= w.very_high) {
        hints.push({ code: 'weight-very-high', level: 'danger', message: `Poids très élevé (≥ ${grouped(w.very_high)} g) : vérifiez la saisie.` });
    } else if (grams >= w.high) {
        hints.push({ code: 'weight-high', level: 'warning', message: `Poids élevé (≥ ${grouped(w.high)} g) : macrosomie possible.` });
    }

    return hints;
};

/** @returns {Hint[]} */
export const apgarHints = (value, ref) => {
    const score = number(value);

    if (score === null) return [];

    const a = ref.apgar;

    if (score <= a.low_max) {
        return [{ code: 'apgar-low', level: 'danger', message: `Score bas (0 à ${a.low_max}) : nouveau-né en détresse — à signaler au médecin.` }];
    }

    if (score <= a.moderate_max) {
        return [{ code: 'apgar-moderate', level: 'warning', message: `Score modérément bas (${a.low_max + 1} à ${a.moderate_max}) : surveillance rapprochée.` }];
    }

    return [{ code: 'apgar-ok', level: 'info', message: `Score rassurant (${a.moderate_max + 1} à ${a.max}).` }];
};

/** @returns {Hint[]} */
export const gestationalAgeHints = (value, ref) => {
    const weeks = number(value);

    if (weeks === null) return [];

    const g = ref.gestational_age;

    if (weeks < g.extremely_preterm) {
        return [{ code: 'ga-extremely-preterm', level: 'danger', message: `Grande prématurité (< ${g.extremely_preterm} SA).` }];
    }

    if (weeks < g.preterm) {
        return [{ code: 'ga-preterm', level: 'warning', message: `Prématurité (< ${g.preterm} SA).` }];
    }

    if (weeks >= g.post_term) {
        return [{ code: 'ga-post-term', level: 'warning', message: `Terme dépassé (≥ ${g.post_term} SA) : à signaler au médecin.` }];
    }

    return [{ code: 'ga-term', level: 'info', message: `À terme (${g.preterm} à ${g.post_term - 1} SA).` }];
};

/**
 * La hauteur utérine se compare au terme (règle de McDonald) — donc seulement
 * quand le terme est connu et dans la fenêtre où la règle vaut.
 *
 * @returns {Hint[]}
 */
export const fundalHeightHints = (heightCm, weeks, ref) => {
    const height = number(heightCm);
    const term = number(weeks);

    if (height === null || term === null) return [];

    const f = ref.fundal_height;

    if (term < f.from_weeks || term > f.to_weeks) return [];

    const gap = height - term;

    if (Math.abs(gap) <= f.tolerance_cm) return [];

    return [{
        code: 'fundal-gap',
        level: 'warning',
        message: gap > 0
            ? `Hauteur utérine supérieure au terme (attendue ≈ ${term} cm à ${term} SA, ± ${f.tolerance_cm} cm).`
            : `Hauteur utérine inférieure au terme (attendue ≈ ${term} cm à ${term} SA, ± ${f.tolerance_cm} cm).`,
    }];
};

/** @returns {Hint[]} */
export const fetalHeartRateHints = (value, ref) => {
    const bpm = number(value);

    if (bpm === null) return [];

    const r = ref.fetal_heart_rate;

    if (bpm < r.very_low) return [{ code: 'fhr-very-low', level: 'danger', message: `Rythme fœtal très bas (< ${r.very_low} bpm) : à signaler sans attendre.` }];
    if (bpm < r.low) return [{ code: 'fhr-low', level: 'warning', message: `Rythme fœtal bas (< ${r.low} bpm) : à recontrôler.` }];
    if (bpm > r.very_high) return [{ code: 'fhr-very-high', level: 'danger', message: `Rythme fœtal très élevé (> ${r.very_high} bpm) : à signaler sans attendre.` }];
    if (bpm > r.high) return [{ code: 'fhr-high', level: 'warning', message: `Rythme fœtal élevé (> ${r.high} bpm) : à recontrôler.` }];

    return [{ code: 'fhr-ok', level: 'info', message: `Dans la plage habituelle (${r.low} à ${r.high} bpm).` }];
};

/** @returns {Hint[]} */
export const dilationHints = (value, ref) => {
    const cm = number(value);

    return cm !== null && cm >= ref.dilation.max
        ? [{ code: 'dilation-complete', level: 'info', message: 'Dilatation complète.' }]
        : [];
};

/** La parité compte des accouchements : elle ne peut pas dépasser le nombre de grossesses. */
export const parityHints = (gravidity, parity) => {
    const g = number(gravidity);
    const p = number(parity);

    return g !== null && p !== null && p > g
        ? [{ code: 'parity-over-gravidity', level: 'warning', message: 'La parité dépasse la gestité : vérifiez les deux valeurs.' }]
        : [];
};

/**
 * Ce que les dernières règles permettent de calculer : le terme estimé (règle
 * de Naegele) et l'âge de la grossesse. Ce sont des **propositions** — le
 * bouton les reprend, jamais d'office, et jamais par-dessus une saisie.
 *
 * @returns {{ dueDate: string, dueDateLabel: string, weeks: number, hints: Hint[] } | null}
 */
export const pregnancyFromLastPeriod = (lastPeriod, dueDate, ref, now = new Date()) => {
    const start = calendarDay(lastPeriod);

    if (start === null) return null;

    const today = toDay(now);
    const elapsedDays = Math.floor((today - start) / DAY_MS);

    /** @type {Hint[]} */
    const hints = [];

    if (elapsedDays < 0) {
        return { dueDate: '', dueDateLabel: '', weeks: 0, hints: [{ code: 'lmp-future', level: 'warning', message: 'Les dernières règles sont dans le futur : vérifiez la date.' }] };
    }

    const due = start + ref.pregnancy.term_days * DAY_MS;
    const weeks = Math.floor(elapsedDays / 7);

    if (elapsedDays > ref.pregnancy.implausible_days) {
        hints.push({ code: 'lmp-too-old', level: 'warning', message: `Plus de ${Math.floor(ref.pregnancy.implausible_days / 30)} mois depuis les dernières règles : vérifiez la date.` });
    } else {
        hints.push({
            code: 'lmp-due-date',
            level: 'info',
            message: `Terme estimé d’après les dernières règles : ${frenchDate(due)} (≈ ${weeks} SA aujourd’hui).`,
            // Proposé seulement si rien n'est saisi : jamais par-dessus une valeur.
            ...(String(dueDate ?? '').trim() === '' ? { action: { label: 'Utiliser', value: isoDate(due) } } : {}),
        });
    }

    return { dueDate: isoDate(due), dueDateLabel: frenchDate(due), weeks, hints };
};

/** Le terme calculé depuis les dernières règles, proposé sous le champ « Terme (semaines) ». */
export const gestationalAgeFromLastPeriod = (lastPeriod, currentWeeks, ref, now = new Date()) => {
    const computed = pregnancyFromLastPeriod(lastPeriod, '', ref, now);

    if (! computed || computed.weeks <= 0 || computed.weeks > ref.gestational_age.max) return [];

    return [{
        code: 'ga-from-lmp',
        level: 'info',
        message: `D’après les dernières règles : ≈ ${computed.weeks} SA.`,
        ...(String(currentWeeks ?? '').trim() === '' ? { action: { label: 'Utiliser', value: computed.weeks } } : {}),
    }];
};

/** @returns {Hint[]} */
export const futureDateHints = (value, label, now = new Date()) => {
    const at = localInstant(value);

    return at !== null && at > now.getTime()
        ? [{ code: `future-${label}`, level: 'warning', message: `${label} : cette date est dans le futur — vérifiez la saisie.` }]
        : [];
};

/** Le travail commence avant l'accouchement. */
export const laborTimingHints = (startedAt, deliveredAt) => {
    const start = localInstant(startedAt);
    const delivery = localInstant(deliveredAt);

    return start !== null && delivery !== null && start > delivery
        ? [{ code: 'labor-after-delivery', level: 'warning', message: 'Le début du travail est postérieur à l’accouchement : vérifiez les deux dates.' }]
        : [];
};

/** Un accouchement gémellaire demandé annonce deux enfants. */
export const newbornCountHints = (count, expected) => (
    expected && count < expected
        ? [{ code: 'newborn-count', level: 'info', message: `${expected} nouveau-nés attendus d’après l’acte demandé ; ${count} fiche${count > 1 ? 's' : ''} ouverte${count > 1 ? 's' : ''}.` }]
        : []
);

/** Le plus grave des messages, pour un repère visuel unique. */
export const worstLevel = (hints) => (['danger', 'warning', 'info'].find((level) => hints.some((hint) => hint.level === level)) ?? null);
