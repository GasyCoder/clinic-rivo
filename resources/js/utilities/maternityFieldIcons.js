import {
    ArrowDownRight,
    BedDouble,
    CalendarCheck,
    CircleCheck,
    CircleDashed,
    CircleEllipsis,
    CircleX,
    Droplets,
    FileSearch,
    Footprints,
    MessageSquareWarning,
    Pill,
    Siren,
    Thermometer,
    Waves,
} from 'lucide-vue-next';

/**
 * ADR-205 — une icône par choix structuré de la consultation prénatale.
 *
 * Les libellés viennent du serveur (`MaternityEncounterFields`) ; seule
 * l'icône est choisie ici, par valeur. Une valeur inconnue n'en reçoit pas :
 * le libellé suffit, rien ne casse.
 */
const ICONS = {
    visit_reasons: {
        SCHEDULED_FOLLOW_UP: CalendarCheck,
        COMPLAINT: MessageSquareWarning,
        RESULT_REVIEW: FileSearch,
        OTHER: CircleEllipsis,
    },
    reported_since_last: {
        EVENT: Siren,
        HOSPITALIZATION: BedDouble,
        TREATMENT_CHANGE: Pill,
        SYMPTOMS: Thermometer,
        FETAL_MOVEMENTS: Footprints,
        CONTRACTIONS: Waves,
        URINARY_SYMPTOMS: Droplets,
        OTHER: CircleEllipsis,
    },
    fetal_movements: {
        PRESENT: CircleCheck,
        DECREASED: ArrowDownRight,
        NOT_ASSESSED: CircleDashed,
    },
    contractions: {
        NO: CircleX,
        YES: Waves,
        NOT_ASSESSED: CircleDashed,
    },
};

/** Les options du serveur, chacune avec son icône quand elle en a une. */
export function withIcons(field, options = []) {
    const icons = ICONS[field] ?? {};

    return options.map((option) => (icons[option.value] ? { ...option, icon: icons[option.value] } : option));
}
