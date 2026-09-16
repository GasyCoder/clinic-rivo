import {
    Activity,
    BarChart3,
    Briefcase,
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock,
    Copy,
    FileSearch,
    FileText,
    FlaskConical,
    Heart,
    LayoutDashboard,
    Microscope,
    Package,
    Pill,
    Plus,
    Scissors,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Stethoscope,
    Syringe,
    Trash2,
    Truck,
    UserRoundCheck,
    UserRoundCog,
    Users,
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
    { key: 'paraclinical-requests', group: 'clinical', text: 'Demandes d’examens', description: 'Analyses et imagerie demandées, et leurs résultats', icon: FileSearch, link: '/medicine/demandes-examens', permission: 'paraclinical_requests.view', tone: 'ocean' },
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
        description: 'Délivrances, stock et approvisionnement',
        icon: Pill,
        link: '/pharmacy/stock',
        activeLinks: ['/pharmacy'],
        permission: 'pharmacy.view',
        tone: 'green',
        // ADR-098 — the sidebar is the Pharmacy's only navigation. Each entry
        // is gated by the permission of the screen it opens. There is no
        // « Accueil »: the Pharmacy's tasks are on the overview page.
        children: [
            { code: 'counter-sale', icon: ShoppingCart, label: 'Vente comptoir', link: '/pharmacy/counter-sales/create', activeLinks: ['/pharmacy/counter-sales'], permission: 'pharmacy.counter_sales.create' },
            { code: 'dispenses', icon: FileText, label: 'Ordonnances à délivrer', link: '/pharmacy/dispenses', activeLinks: ['/pharmacy/dispenses'], permission: 'prescriptions.view' },
            { code: 'care-consumables', icon: UserRoundCheck, label: 'Consommables Soins', link: '/pharmacy/care-consumables', activeLinks: ['/pharmacy/care-consumables'], permission: 'care_consumables.view' },
            // ADR-098 — Stock and Médicaments were one list twice: one page now.
            { code: 'medicines-stock', icon: Pill, label: 'Médicaments & stock', link: '/pharmacy/stock', activeLinks: ['/pharmacy/stock', '/pharmacy/medicines'], anyPermission: ['stock.view', 'medicines.view'] },
            // ADR-098 — orders, receptions and supplier invoices: one purchasing page with tabs.
            { code: 'purchases', icon: Truck, label: 'Achats', link: '/pharmacy/purchases', activeLinks: ['/pharmacy/purchases', '/pharmacy/purchase-orders', '/pharmacy/receipts', '/pharmacy/supplier-invoices'], anyPermission: ['purchase_orders.view', 'goods_receipts.view', 'supplier_invoices.view'] },
            { code: 'suppliers', icon: Building2, label: 'Fournisseurs', link: '/pharmacy/suppliers', activeLinks: ['/pharmacy/suppliers'], permission: 'medicine_suppliers.view' },
        ],
    },
    // Gestion
    {
        key: 'hr',
        group: 'management',
        text: 'Ressources humaines',
        description: 'Employés et opérations RH',
        icon: Briefcase,
        link: '/administration',
        // « Accueil RH » itself; its sub-pages come from activeLinks, and
        // /administration/users stays with « Utilisateurs & accès ».
        exact: true,
        // ADR-066 — the sidebar is the HR space's only navigation (no tab bar).
        activeLinks: ['/administration/employees', '/administration/contracts', '/administration/generated-documents', '/administration/attendance', '/administration/leave', '/administration/planning', '/administration/reports', '/administration/staff-block-credits', '/administration/settings'],
        permission: 'employees.view',
        tone: 'navy',
        children: [
            { code: 'hr-home', icon: LayoutDashboard, label: 'Accueil RH', link: '/administration', permission: 'employees.view' },
            { code: 'hr-employees', icon: Users, label: 'Employés', link: '/administration/employees', activeLinks: ['/administration/employees'], permission: 'employees.view' },
            { code: 'hr-contracts', icon: FileText, label: 'Contrats', link: '/administration/contracts', activeLinks: ['/administration/contracts'], permission: 'contracts.view' },
            { code: 'hr-documents', icon: Copy, label: 'Documents', link: '/administration/generated-documents', activeLinks: ['/administration/generated-documents'], permission: 'generated_documents.view' },
            { code: 'hr-attendance', icon: Clock, label: 'Présences', link: '/administration/attendance', activeLinks: ['/administration/attendance'], permission: 'attendance.view' },
            { code: 'hr-leave', icon: CalendarDays, label: 'Congés', link: '/administration/leave', activeLinks: ['/administration/leave'], permission: 'leave.view' },
            { code: 'hr-planning', icon: CalendarRange, label: 'Planning', link: '/administration/planning', activeLinks: ['/administration/planning'], permission: 'planning.view' },
            { code: 'hr-reports', icon: BarChart3, label: 'Rapports', link: '/administration/reports', activeLinks: ['/administration/reports'], permission: 'hr_reports.view' },
            { code: 'hr-block-credit', icon: Wallet, label: 'Crédit Bloc', link: '/administration/staff-block-credits', activeLinks: ['/administration/staff-block-credits'], permission: 'staff_block_credits.view' },
            { code: 'hr-settings', icon: Settings, label: 'Paramètres', link: '/administration/settings', activeLinks: ['/administration/settings'], permission: 'hr_settings.view' },
        ],
    },
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
