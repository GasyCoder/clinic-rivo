/**
 * « Décision & clôture » (ADR-203).
 *
 * Le médecin choisit la conduite à tenir, la précise s'il le veut, et
 * « Clôturer » la transmet puis conclut la rencontre en un seul geste. Aucune
 * autre condition : ni diagnostic, ni étape validée, ni question « le
 * diagnostic peut-il être posé maintenant ? ». Le serveur rejuge tout
 * (`SubmitConsultationDecisionAction`) ; ce module ne fait que composer ce qui
 * part et relire ce qui sera signé.
 */
import { toLocalDateInput } from './date.js';

/** Ce qu'une conduite apporte, dit en une ligne sous son choix. */
export const DECISION_HINTS = {
    DISCHARGE: 'Le patient rentre chez lui : la sortie médicale est prononcée à la clôture.',
    HOSPITALIZATION: 'Le patient est admis dès la clôture ; son séjour se complète dans Hospitalisation.',
    SURGERY: 'Une demande part au bloc avec l’intervention envisagée ; la Chirurgie la programme.',
    REFERRAL: 'Transfert vers un autre établissement, suivi jusqu’au départ dans Transferts.',
    MATERNITY: 'Le patient rejoint la file Maternité.',
    PEDIATRICS: 'L’enfant rejoint la file Pédiatrie.',
    CONTINUED_HOSPITALIZATION: 'Le patient reste dans son lit : rien n’est demandé à personne.',
};

/** Les conduites qui partent vers un service : elles portent une priorité et des consignes. */
const SERVICE_TYPES = ['HOSPITALIZATION', 'SURGERY', 'REFERRAL', 'MATERNITY', 'PEDIATRICS'];

export const isServiceDecision = (type) => SERVICE_TYPES.includes(type);

/** L'état du patient à la sortie : un constat, jamais pré-coché. */
export const DISCHARGE_CONDITIONS = [
    { value: 'Guéri', label: 'Guéri', tone: 'positive' },
    { value: 'Amélioré', label: 'Amélioré', tone: 'positive' },
    { value: 'Stable', label: 'Stable', tone: 'neutral' },
    { value: 'Non amélioré', label: 'Non amélioré', tone: 'warning' },
    { value: 'Aggravé', label: 'Aggravé', tone: 'warning' },
];

export const FOLLOW_UPS = [
    { days: 3, label: 'Dans 3 jours' },
    { days: 7, label: 'Dans 7 jours' },
    { days: 15, label: 'Dans 15 jours' },
    { days: 30, label: 'Dans 1 mois' },
];

/** La date d'un contrôle « dans N jours », comptée dans le fuseau du poste. */
export function followUpDate(days, from = new Date()) {
    const date = new Date(from);
    date.setDate(date.getDate() + days);

    return toLocalDateInput(date);
}

/** « 2026-09-30 » lu « 30/09/2026 », sans passer par un fuseau. */
const dayLabel = (value) => {
    const [year, month, day] = String(value ?? '').split('-');

    return year && month && day ? `${day}/${month}/${year}` : null;
};

const text = (value) => {
    const trimmed = String(value ?? '').trim();

    return trimmed === '' ? null : trimmed;
};

/**
 * Une conduite déjà fixée ne se renvoie pas : une demande déjà transmise, ou
 * une sortie déjà prononcée. La clôture la retrouve telle qu'elle est.
 */
export const decisionIsLocked = ({ active = null, medicalDischarge = null } = {}) => Boolean(
    medicalDischarge || active?.status === 'SUBMITTED',
);

/**
 * Ce qui part avec « Clôturer ». Seuls les champs de la conduite choisie :
 * une chirurgie n'envoie pas d'état de sortie, une sortie pas de priorité.
 */
export function decisionPayload(form, context = {}) {
    if (decisionIsLocked(context) || !form?.type) return {};

    const payload = { type: form.type };

    if (isServiceDecision(form.type)) {
        payload.priority = form.priority || null;
        payload.notes = text(form.notes);
    }

    if (form.type === 'SURGERY') payload.catalog_item_uuid = form.catalog_item_uuid || null;
    if (form.type === 'REFERRAL') payload.facility = text(form.facility);

    if (form.type === 'DISCHARGE') {
        payload.discharge_type = form.discharge_type || 'NORMAL';

        // Un décès : l'heure, le lieu et les causes s'établissent au registre
        // (ADR-107) ; aucune consigne n'a de destinataire.
        if (payload.discharge_type !== 'DECEASED') {
            payload.patient_condition = form.patient_condition || null;
            // Présente, la clé dit ce que le médecin veut remettre — vidée,
            // elle veut dire « aucun traitement de sortie ».
            payload.discharge_prescription = text(form.discharge_prescription);
            payload.recommendations = text(form.recommendations);
            payload.follow_up_at = form.follow_up_at || null;
        }
    }

    return payload;
}

/**
 * La seule chose qui retient « Clôturer » : aucune conduite choisie — ou une
 * chirurgie sans son intervention, que le bloc ne peut pas programmer
 * (ADR-114, ADR-159). Tout le reste est facultatif.
 */
export function closureMissing(form, context = {}) {
    if (decisionIsLocked(context)) return null;
    if (!form?.type) return 'Choisissez la conduite à tenir.';
    if (form.type === 'SURGERY' && !form.catalog_item_uuid) return 'Chirurgie : choisissez l’intervention envisagée.';

    return null;
}

const labelOf = (options, value) => (options ?? []).find((option) => option.value === value)?.label ?? null;

/** Ce que la fenêtre de clôture relit : ce qui part réellement, jamais un résumé deviné. */
export function decisionSummary(form, context = {}) {
    const {
        types = [], dischargeTypes = [], priorities = [], surgeryCatalog = [],
        active = null, medicalDischarge = null,
    } = context;

    if (medicalDischarge) {
        return [{ label: 'Conduite à tenir', value: `Sortie médicale — ${medicalDischarge.type_label ?? 'déjà prononcée'}` }];
    }

    if (active?.status === 'SUBMITTED') {
        return [
            { label: 'Conduite à tenir', value: `${active.type_label} — déjà transmise` },
            { label: 'Demande', value: active.request?.summary ?? null },
        ].filter((line) => line.value);
    }

    if (!form?.type) return [];

    const lines = [{ label: 'Conduite à tenir', value: labelOf(types, form.type) ?? form.type }];

    if (form.type === 'DISCHARGE') {
        const dischargeType = form.discharge_type || 'NORMAL';
        lines.push({ label: 'Type de sortie', value: labelOf(dischargeTypes, dischargeType) ?? dischargeType });

        if (dischargeType === 'DECEASED') {
            lines.push({ label: 'Acte de constatation', value: 'À établir au registre des décès (heure, lieu, causes).' });
        } else {
            lines.push({ label: 'État du patient', value: form.patient_condition || null });
            lines.push({ label: 'Traitement de sortie', value: text(form.discharge_prescription) });
            lines.push({ label: 'Conseils', value: text(form.recommendations) });
            lines.push({ label: 'Contrôle', value: dayLabel(form.follow_up_at) });
        }
    }

    if (form.type === 'SURGERY') {
        lines.push({
            label: 'Intervention envisagée',
            value: surgeryCatalog.find((item) => item.uuid === form.catalog_item_uuid)?.name ?? null,
        });
    }

    if (form.type === 'REFERRAL') {
        lines.push({ label: 'Établissement', value: text(form.facility) ?? 'À préciser dans Transferts' });
    }

    if (isServiceDecision(form.type)) {
        lines.push({ label: 'Priorité', value: labelOf(priorities, form.priority) });
        lines.push({ label: 'Consignes', value: text(form.notes) });
    }

    return lines.filter((line) => line.value);
}

/** Le service qui reçoit le patient, dit comme on le transmet. */
const TRANSMIT_TO = {
    HOSPITALIZATION: { to: 'à l’Hospitalisation', notice: 'Il est admis dès la confirmation ; son séjour se complète dans Hospitalisation.' },
    SURGERY: { to: 'au bloc opératoire', notice: 'La demande part au bloc dès la confirmation ; la Chirurgie programme l’intervention.' },
    MATERNITY: { to: 'à la Maternité', notice: 'Il rejoint la file Maternité dès la confirmation.' },
    PEDIATRICS: { to: 'à la Pédiatrie', notice: 'Il rejoint la file Pédiatrie dès la confirmation.' },
    REFERRAL: { to: 'aux Transferts', notice: 'Le transfert est suivi dans Transferts jusqu’au départ du patient.' },
};

/**
 * Ce que fait réellement « Clôturer », dit sur le bouton (demande du
 * propriétaire, 2026-09-27) : une conduite vers un service **transmet** le
 * patient avant de clôturer, et le bouton doit le dire. Pour une sortie ou une
 * conduite déjà partie, il n'y a personne à qui transmettre : le libellé ne
 * le prétend pas.
 */
export function closureAction(form, context = {}) {
    const plain = {
        transmits: false,
        button: 'Clôturer la consultation',
        title: 'Clôturer la consultation',
        confirm: 'Je confirme et clôture',
        notice: null,
    };

    if (decisionIsLocked(context) || !form?.type) return plain;

    if (form.type === 'DISCHARGE') {
        return (form.discharge_type || 'NORMAL') === 'DECEASED'
            ? {
                ...plain,
                button: 'Prononcer le décès et clôturer',
                title: 'Prononcer le décès et clôturer',
                confirm: 'Je prononce le décès et je clôture',
                notice: 'L’acte de constatation s’établit ensuite au registre des décès.',
            }
            : {
                ...plain,
                button: 'Prononcer la sortie et clôturer',
                title: 'Prononcer la sortie et clôturer',
                confirm: 'Je prononce la sortie et je clôture',
                notice: 'La sortie médicale est prononcée ; la sortie administrative reste à la Réception.',
            };
    }

    const destination = TRANSMIT_TO[form.type];

    if (!destination) {
        return { ...plain, notice: 'Le patient garde son lit ; son séjour continue.' };
    }

    return {
        transmits: true,
        button: 'Transmettre et clôturer',
        title: `Transmettre ${destination.to} et clôturer`,
        confirm: `Je transmets ce patient ${destination.to} et je clôture`,
        notice: `Le patient est transmis ${destination.to}. ${destination.notice}`,
    };
}
