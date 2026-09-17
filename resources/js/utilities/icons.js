import {
    Activity,
    ArrowRight,
    Banknote,
    Briefcase,
    Building2,
    CalendarCheck,
    ClipboardList,
    CreditCard,
    FileText,
    FlaskConical,
    Heart,
    History,
    LayoutGrid,
    MapPin,
    Package,
    Pill,
    Plus,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Scissors,
    Stethoscope,
    Trash2,
    Truck,
    TrendingUp,
    UserRoundCheck,
    UsersRound,
    Wallet,
    WalletMinimal,
} from 'lucide-vue-next';

/**
 * Noms d'icônes venus du serveur → composant lucide.
 *
 * Certaines icônes ne sont pas choisies par le frontend : les indicateurs du
 * tableau de bord (`ClinicOverviewService`) et les modules de chaque site
 * dans le portail (`PortalDirectory`) arrivent avec un nom. Ces noms sont
 * ceux de l'ancien jeu DashWind ; les traduire ici évite d'avoir à modifier
 * le backend — et surtout d'avoir à le modifier *deux fois*, puisque les
 * mêmes noms servent encore aux écrans non migrés.
 *
 * Un nom inconnu retombe sur une icône neutre plutôt que sur un vide : une
 * carte sans repère visuel se lit plus mal qu'une carte au repère générique.
 */
const BY_NAME = {
    activity: Activity,
    'activity-round': Stethoscope,  // consultations — même repère que le menu Médecine
    'arrow-right': ArrowRight,
    'arrow-right-round': ArrowRight,
    briefcase: Briefcase,
    building: Building2,
    calendar: CalendarCheck,
    capsule: Pill,
    'card-view': ClipboardList,
    'file-text': FileText,
    growth: TrendingUp,
    heart: Heart,
    history: History,
    'list-index': FileText,
    'map-pin': MapPin,
    masks: Scissors,  // demandes chirurgicales — même repère que le menu Chirurgie
    money: Banknote,
    package: Package,
    plus: Plus,
    'setting-alt': Settings,
    'shield-check': ShieldCheck,
    'shopping-cart': ShoppingCart,
    trash: Trash2,
    truck: Truck,
    'user-check': UserRoundCheck,
    users: UsersRound,
    wallet: Wallet,
    'wallet-out': WalletMinimal,
    'flask': FlaskConical,
    'credit-card': CreditCard,
};

export function lucideIcon(name) {
    return BY_NAME[name] ?? LayoutGrid;
}
