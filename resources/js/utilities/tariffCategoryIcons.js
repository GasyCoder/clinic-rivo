import {
    Ambulance,
    Baby,
    Bandage,
    BedDouble,
    CircleHelp,
    ConciergeBell,
    Eye,
    FlaskConical,
    HeartPulse,
    Landmark,
    LayoutGrid,
    Pill,
    ScanLine,
    Scissors,
    Smile,
    Stethoscope,
    Users,
} from 'lucide-vue-next';
import { IMAGING_UNCLASSIFIED, groupModule } from '@/utilities/catalogGroups';

/**
 * L'icône de chaque catégorie de « Tarifs & mutuelles » : la même dans la
 * navigation et dans l'en-tête de la catégorie ouverte. Une catégorie inconnue
 * garde une icône neutre plutôt que rien.
 */
const ICONS = {
    RECEPTION: ConciergeBell,
    MEDICINE: Stethoscope,
    CARE: Bandage,
    LABORATORY: FlaskConical,
    'IMAGING:ULTRASOUND': ScanLine,
    'IMAGING:CARDIOLOGY': HeartPulse,
    [IMAGING_UNCLASSIFIED]: CircleHelp,
    PHARMACY: Pill,
    SURGERY: Scissors,
    ADMINISTRATION: Landmark,
    MATERNITY: Baby,
    HOSPITALIZATION: BedDouble,
    TRANSFER: Ambulance,
    PEDIATRICS: Smile,
    OPHTHALMOLOGY: Eye,
    FAMILY_PLANNING: Users,
};

export const categoryIcon = (key) => ICONS[key] ?? ICONS[groupModule(key)] ?? LayoutGrid;
