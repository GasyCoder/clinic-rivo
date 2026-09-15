import {
    Activity,
    Briefcase,
    ClipboardList,
    FileSearch,
    FlaskConical,
    Heart,
    Microscope,
    Package,
    Pill,
    Plus,
    Scissors,
    Settings,
    ShieldCheck,
    Stethoscope,
    Syringe,
    Trash2,
    UserRoundCheck,
    UserRoundCog,
    UsersRound,
    Wallet,
    WalletMinimal,
} from 'lucide-vue-next';

/**
 * The clinic's workspaces, defined once.
 *
 * The sidebar and the "Vue d'ensemble" read this same list, so a module can
 * never appear in one and not the other, nor be gated by two different
 * permissions. Visibility is always decided by `permission` — the role only
 * chooses what comes first (see ROLE_FOCUS), never what is allowed.
 */
export const CLINIC_WORKSPACES = [
    // Gestion clinique
    { key: 'reception', group: 'clinical', text: 'Réception', description: 'Passages, urgences et orientation', icon: ClipboardList, link: '/reception', permission: 'reception.view', tone: 'navy' },
    // CDC §33.3 — les passages que le médecin a terminés et qui attendent
    // la décision administrative : contrôle du compte puis sortie.
    { key: 'settlements', group: 'clinical', text: 'Sorties & règlements', description: 'Comptes à solder et sorties administratives', icon: WalletMinimal, link: '/reception/sorties', permission: 'episodes.settlement.view', tone: 'ocean' },
    { key: 'cash', group: 'clinical', text: 'Caisse', description: 'Factures, règlements, session', icon: Wallet, link: '/cash', activeLinks: ['/cash', '/receipts'], permission: 'cash.view', tone: 'green' },
    { key: 'patients', group: 'clinical', text: 'Patients', description: 'Dossiers et historique des passages', icon: UsersRound, link: '/patients', permission: 'patients.view', tone: 'cyan' },
    { key: 'medicine', group: 'clinical', text: 'Médecine', description: 'File d’attente et consultations', icon: Stethoscope, link: '/medicine', permission: 'consultations.view', tone: 'ocean' },
    // Les demandes d'examens du médecin, toutes consultations confondues :
    // suivre un résultat ne devait plus obliger à rouvrir le passage de tête.
    { key: 'paraclinical-requests', group: 'clinical', text: 'Demandes d’examens', description: 'Analyses et imagerie demandées, et leurs résultats', icon: FileSearch, link: '/medicine/demandes-examens', permission: 'laboratory_orders.view', tone: 'ocean' },
    { key: 'laboratory', group: 'clinical', text: 'Laboratoire', description: 'Demandes et résultats d’analyses', icon: FlaskConical, link: '/laboratory', permission: 'laboratory_orders.view', tone: 'cyan' },
    // care.view alone also powers the read-only projection embedded in
    // Médecine/Chirurgie's own dossier pages (ADR-048/054) — gating on
    // care.update keeps the Soins queue for the role that operates it.
    { key: 'care', group: 'clinical', text: 'Soins', description: 'Constantes et fiches de soins', icon: UserRoundCheck, link: '/care', permission: 'care.update', tone: 'green' },
    { key: 'maternity', group: 'clinical', text: 'Maternité', description: 'Suivi et actes de Maternité', icon: Heart, link: '/maternity', permission: 'maternity.view', tone: 'yellow' },
    { key: 'surgery', group: 'clinical', text: 'Chirurgie', description: 'Demandes et suivi du Bloc', icon: Scissors, link: '/surgery', permission: 'surgery.view', tone: 'yellow' },
    { key: 'anesthesia', group: 'clinical', text: 'Anesthésie', description: 'Évaluations anesthésiques', icon: Syringe, link: '/anesthesia', permission: 'anesthesia.view', tone: 'cyan' },
    {
        key: 'pharmacy',
        group: 'clinical',
        text: 'Pharmacie',
        description: 'Délivrances, lots et stock',
        icon: Pill,
        link: '/pharmacy',
        resolveLink: (can) => (can('pharmacy.counter_sales.create') ? '/pharmacy/counter-sales/create' : '/pharmacy'),
        activeLinks: ['/pharmacy'],
        permission: 'pharmacy.view',
        tone: 'green',
    },
    // Gestion
    { key: 'hr', group: 'management', text: 'Ressources humaines', description: 'Employés et opérations RH', icon: Briefcase, link: '/administration', exact: true, permission: 'employees.view', tone: 'navy' },
    { key: 'logistics', group: 'management', text: 'Logistique', description: 'Inventaire et équipements', icon: Package, link: '/logistics', permission: 'logistics.view', tone: 'yellow' },
    { key: 'guarding', group: 'management', text: 'Gardiennage', description: 'Visiteurs et contrôle des sorties', icon: ShieldCheck, link: '/reception/visitors', permission: 'guarding.view', tone: 'ocean' },
    { key: 'users', group: 'management', text: 'Utilisateurs & accès', description: 'Comptes et permissions', icon: UserRoundCog, link: '/administration/users', activeLinks: ['/administration/users'], permission: 'users.view', tone: 'cyan' },
    { key: 'catalog', group: 'management', text: 'Référentiels & tarifs', description: 'Désignations et grilles tarifaires', icon: Settings, link: '/administration/catalog', activeLinks: ['/administration/catalog'], permission: 'catalog.items.view', tone: 'navy' },
    { key: 'analysis_catalog', group: 'management', text: 'Catalogue analyses', description: 'Analyses et valeurs de référence', icon: Microscope, link: '/administration/analyses', activeLinks: ['/administration/analyses'], permission: 'analysis_catalog.view', tone: 'cyan' },
    { key: 'trash', group: 'management', text: 'Corbeille', description: 'Éléments supprimés du site', icon: Trash2, link: '/trash', permission: 'trash.view', tone: 'navy' },
];

export const WORKSPACE_GROUPS = {
    clinical: 'Gestion clinique',
    management: 'Gestion',
};

/**
 * What each role does first when it opens the application.
 *
 * `primary` is the one action the role starts its day with, `shortcuts` the
 * workspaces it jumps to next, `metrics` the indicators it reads first (keys
 * from ClinicOverviewService). Every entry is still checked against the
 * account's permissions: an individual DENY hides it, an individual ALLOW on
 * another module simply adds that module after the role's own.
 */
export const ROLE_FOCUS = {
    RECEPTION: {
        lead: 'Accueillez, orientez et suivez les passages du jour.',
        primary: { label: 'Nouvelle prise en charge', link: '/reception/patients', icon: Plus, permission: 'episodes.create' },
        shortcuts: ['reception', 'settlements', 'patients', 'cash'],
        metrics: ['passages_today', 'pending_orientation', 'patients_today', 'payments_today', 'visitors_today'],
    },
    NURSE: {
        lead: 'Vos patients en attente aux Soins et les fiches du jour.',
        primary: { label: 'Ouvrir la file Soins', link: '/care', icon: UserRoundCheck, permission: 'care.update' },
        shortcuts: ['maternity', 'anesthesia', 'patients'],
        metrics: ['care_records_today', 'passages_today'],
    },
    MEDICINE: {
        lead: 'Votre file de consultation et les demandes du jour.',
        primary: { label: 'Ouvrir la file Médecine', link: '/medicine', icon: Activity, permission: 'consultations.view' },
        shortcuts: ['laboratory', 'patients', 'surgery'],
        metrics: ['consultations_today', 'surgical_requests_today', 'care_records_today'],
    },
    SURGERY: {
        lead: 'Les demandes chirurgicales et le suivi du Bloc.',
        primary: { label: 'Ouvrir le Bloc', link: '/surgery', icon: Stethoscope, permission: 'surgery.view' },
        shortcuts: ['anesthesia', 'patients'],
        metrics: ['surgical_requests_today', 'consultations_today'],
    },
    PHARMACY: {
        lead: 'Délivrances, ventes comptoir et état du stock.',
        primary: { label: 'Nouvelle vente comptoir', link: '/pharmacy/counter-sales/create', icon: Plus, permission: 'pharmacy.counter_sales.create' },
        shortcuts: ['pharmacy'],
        metrics: ['pharmacy_requests_today'],
    },
    LABORATORY: {
        lead: 'Les demandes d’analyses et leurs résultats.',
        primary: { label: 'Ouvrir le Laboratoire', link: '/laboratory', icon: Activity, permission: 'laboratory_orders.view' },
        shortcuts: ['analysis_catalog', 'patients'],
        metrics: [],
    },
    ADMINISTRATION: {
        lead: 'Personnel, contrats, présences et congés.',
        primary: { label: 'Ouvrir les Ressources humaines', link: '/administration', icon: Briefcase, permission: 'employees.view' },
        shortcuts: ['users', 'catalog', 'logistics'],
        metrics: ['employees_today'],
    },
    LOGISTICS: {
        lead: 'Inventaire, affectations et état des équipements.',
        primary: { label: 'Ouvrir la Logistique', link: '/logistics', icon: Package, permission: 'logistics.view' },
        shortcuts: [],
        metrics: [],
    },
    SUPPORT: {
        lead: 'Entrées, sorties et visiteurs présents.',
        primary: { label: 'Ouvrir le Gardiennage', link: '/reception/visitors', icon: ShieldCheck, permission: 'guarding.view' },
        shortcuts: [],
        metrics: ['visitors_today'],
    },
    MAINTENANCE: {
        lead: 'Suivi et maintenance des équipements.',
        primary: { label: 'Ouvrir la Logistique', link: '/logistics', icon: Package, permission: 'logistics.view' },
        shortcuts: [],
        metrics: [],
    },
};
