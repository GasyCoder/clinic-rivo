import {
    Ambulance, Baby, BedDouble, Bandage, Briefcase, Building2, ClipboardList, Compass, DoorOpen, FileSearch, FlaskConical,
    Heart, KeyRound, Pill, ScrollText, Scissors, ShieldCheck, Stethoscope, Syringe, Tags, UsersRound, Wallet,
} from 'lucide-vue-next';

/**
 * ADR-222 — l'icône de chaque module de l'aide (clé d'AssistantKnowledge), la même
 * que dans le menu : une catégorie de questions se reconnaît d'un coup d'œil.
 * Un module inconnu prend la boussole.
 */
export const ASSISTANT_MODULE_ICONS = Object.freeze({
    general: Compass,
    reception: ClipboardList,
    settlement: DoorOpen,
    cash: Wallet,
    patients: UsersRound,
    care: Bandage,
    medicine: Stethoscope,
    laboratory: FlaskConical,
    paraclinical: FileSearch,
    hospitalization: BedDouble,
    surgery: Scissors,
    anesthesia: Syringe,
    maternity: Heart,
    pharmacy: Pill,
    transfers: Ambulance,
    pediatrics: Baby,
    deaths: ScrollText,
    security: ShieldCheck,
    hr: Briefcase,
    referentials: Tags,
    access: KeyRound,
    superadmin: Building2,
});

export const assistantModuleIcon = (key) => ASSISTANT_MODULE_ICONS[key] ?? Compass;
