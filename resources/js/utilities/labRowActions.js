import { Eye, Play, RotateCcw, Send, StepForward } from 'lucide-vue-next';
import { LAB_ROW_ACTIONS } from '@/utilities/labWorkbench';

/**
 * ADR-217 / ADR-219 — le bouton d'une demande d'analyses, le même dans la file du
 * laboratoire et dans « Demandes d'examens » : Traiter, Continuer, Reprendre,
 * Envoyer, Voir. Le serveur choisit le geste ; ceci ne fait que le dessiner.
 */
const ICONS = { start: Play, continue: StepForward, redo: RotateCcw, send: Send, open: Eye };
const VARIANTS = { start: 'default', continue: 'warning', redo: 'danger-outline', send: 'default', open: 'outline' };

export const labRowButton = (key) => {
    const action = LAB_ROW_ACTIONS[key] ? key : 'open';

    return { key: action, ...LAB_ROW_ACTIONS[action], icon: ICONS[action], variant: VARIANTS[action] };
};
