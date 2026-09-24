import { ArrowRight, Bandage, FlaskConical, Heart, Pill, Scissors, Stethoscope } from 'lucide-vue-next';

/**
 * ADR-177 — la prochaine étape suggérée par la Réception, telle qu'elle se dit.
 *
 * La liste des choix vient du serveur (`ReceptionNextStep::options()`) : ici ne
 * vit que l'icône de chaque service — la même que dans le menu latéral, pour
 * qu'un service se reconnaisse partout au même dessin.
 *
 * Une suggestion est indicative : elle ne décide pas qui voit le passage et ne
 * vaut pas orientation. « Aucune suggestion » est un état normal, jamais une
 * anomalie.
 */
const ICONS = {
    CARE: Bandage,
    MEDICINE: Stethoscope,
    MATERNITY: Heart,
    LABORATORY: FlaskConical,
    PHARMACY: Pill,
    SURGERY: Scissors,
};

export const nextStepIcon = (value) => ICONS[value] ?? ArrowRight;

/** « Soins, Médecine », dans l'ordre des options — jamais dans l'ordre des clics. */
export const orderedNextSteps = (values, options) => {
    const wanted = new Set(values ?? []);

    return (options ?? []).map((option) => option.value).filter((value) => wanted.has(value));
};

export const toggleNextStep = (values, value, checked, options) => {
    const next = new Set(values ?? []);

    if (checked) next.add(value);
    else next.delete(value);

    return orderedNextSteps([...next], options);
};
