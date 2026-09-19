import {
    Ambulance,
    Baby,
    Bandage,
    BedDouble,
    CircleDot,
    ConciergeBell,
    Eye,
    FlaskConical,
    LogOut,
    Pill,
    Receipt,
    ScanLine,
    Scissors,
    Smile,
    Stethoscope,
    Users,
    Wallet,
} from 'lucide-vue-next';

/**
 * L'apparence d'une étape du parcours d'un passage (ADR-117).
 *
 * Le contenu — libellés, suite décidée par le médecin, actes demandés — est
 * composé côté Laravel (`EpisodePathwayTimeline`) : ici ne vit que la façon de
 * le dessiner, partagée par la liste détaillée du « Détail du passage » et par
 * la frise compacte du dossier patient, pour qu'un même état ait la même
 * couleur et la même icône aux deux endroits.
 */
const MODULE_ICONS = {
    RECEPTION: ConciergeBell,
    MEDICINE: Stethoscope,
    CARE: Bandage,
    LABORATORY: FlaskConical,
    IMAGING: ScanLine,
    PHARMACY: Pill,
    SURGERY: Scissors,
    MATERNITY: Baby,
    HOSPITALIZATION: BedDouble,
    TRANSFER: Ambulance,
    PEDIATRICS: Smile,
    OPHTHALMOLOGY: Eye,
    FAMILY_PLANNING: Users,
};

const TYPE_ICONS = {
    INVOICE: Receipt,
    PAYMENT: Wallet,
    EXIT: LogOut,
};

const STATES = {
    DONE: {
        badge: 'success',
        bubble: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300',
        pill: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    },
    ACTIVE: {
        badge: 'secondary',
        bubble: 'bg-primary/10 text-primary',
        pill: 'border-primary/30 bg-primary/10 text-primary',
    },
    PENDING: {
        badge: 'outline',
        bubble: 'bg-muted text-muted-foreground',
        pill: 'border-border bg-muted text-muted-foreground',
    },
    WARNING: {
        badge: 'warning',
        bubble: 'bg-amber-50 text-amber-600 dark:bg-amber-950/30 dark:text-amber-300',
        pill: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    },
    CANCELLED: {
        badge: 'destructive',
        bubble: 'bg-muted text-muted-foreground/60',
        pill: 'border-border bg-muted text-muted-foreground/70 line-through',
    },
};

const FOLLOW_UP_SHORT = {
    RETURN_TO_MEDICINE: 'retour Médecine',
    DIRECT_EXIT: 'sortie directe',
};

export const stepIcon = (step) => TYPE_ICONS[step.type] ?? MODULE_ICONS[step.module] ?? CircleDot;

export const stepStyle = (step) => STATES[step.state] ?? STATES.PENDING;

/**
 * Deux étapes vers le même service ne portent jamais le même mot : « Soins 1 »
 * et « Soins 2 » se lisent comme deux demandes, alors que « Soins », « Soins »
 * se lisait comme un doublon. Un service visité une seule fois garde son nom.
 */
export const pillLabel = (step) => (step.sequence_total > 1 ? `${step.label} ${step.sequence}` : step.label);

/** La suite décidée par le médecin, en deux mots — la frise n'a pas la place d'une phrase. */
export const followUpShort = (step) => FOLLOW_UP_SHORT[step.follow_up?.code] ?? null;

/** Le détail d'une étape en infobulle : la frise reste courte, rien n'est perdu. */
export const stepTitle = (step) => [
    `${pillLabel(step)}${step.state_label ? ` — ${step.state_label}` : ''}`,
    step.from_label ? `${step.from_label} → ${step.label}` : null,
    ...step.notes,
    step.follow_up?.label ?? null,
].filter(Boolean).join('\n');

/** « 1 » se tait, « 2 » se dit : `Injection IM` mais `Perfusion ×2`. */
export const itemQuantity = (quantity) => {
    const value = Number(quantity);

    return Number.isFinite(value) && value > 1 ? `×${Number.isInteger(value) ? value : value.toString()}` : null;
};
