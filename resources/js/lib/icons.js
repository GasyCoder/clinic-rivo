import {
    Activity,
    Baby,
    HeartPulse,
    Banknote,
    ChartLine,
    ClipboardList,
    Coins,
    Filter,
    History,
    Layers,
    LayoutGrid,
    ListOrdered,
    Settings2,
    ShieldCheck,
    ShoppingBag,
    Trash2,
    UsersRound,
    Wallet,
    Briefcase,
    Building2,
    CircleCheck,
    Pencil,
    FileSpreadsheet,
    FileText,
    Folder,
    Inbox,
    Link2,
    List,
    ListChecks,
    Lock,
    Package,
    Pill,
    Receipt,
    Search,
    Truck,
    User,
    UserCheck,
    Users,
} from 'lucide-vue-next';

/**
 * Les noms d'icônes DashWind, traduits en composants lucide.
 *
 * Plusieurs composants partagés — `EmptyState`, `ExplorerView`,
 * `ExplorerTile`, `FolderCard` — reçoivent leur icône sous forme de
 * **chaîne**, depuis une trentaine d'écrans. Changer ce contrat pour passer
 * le composant lucide directement aurait obligé à retoucher tous ces
 * appelants en même temps, donc à modifier des écrans qu'on ne vérifie pas.
 *
 * Cette table laisse le contrat intact et fait disparaître la police
 * d'icônes DashWind du rendu (ADR-099). Un nom inconnu retombe sur un
 * repère neutre plutôt que sur un carré vide : une icône manquante ne doit
 * pas casser un écran.
 */
const ICONS = {
    activity: Activity,
    briefcase: Briefcase,
    building: Building2,
    capsule: Pill,
    'check-circle': CircleCheck,
    // Valeur par défaut de `FormSection` : sans elle, chaque bloc de
    // formulaire qui n'en précise aucune retomberait sur le repère neutre.
    edit: Pencil,
    'file-docs': FileText,
    'file-text': FileText,
    'file-xls': FileSpreadsheet,
    folder: Folder,
    'folder-fill': Folder,
    bag: ShoppingBag,
    'card-view': LayoutGrid,
    coins: Coins,
    filter: Filter,
    'grid-alt': LayoutGrid,
    growth: ChartLine,
    heart: HeartPulse,
    history: History,
    inbox: Inbox,
    layers: Layers,
    'list-index': ListOrdered,
    money: Banknote,
    reports: ClipboardList,
    'setting-alt': Settings2,
    'shield-check': ShieldCheck,
    trash: Trash2,
    'user-check': UserCheck,
    'user-list': UsersRound,
    newborn: Baby,
    wallet: Wallet,
    invoice: Receipt,
    link: Link2,
    list: List,
    'list-check': ListChecks,
    lock: Lock,
    package: Package,
    search: Search,
    truck: Truck,
    user: User,
    users: Users,
};

export const lucideIcon = (name) => ICONS[name] ?? Inbox;
