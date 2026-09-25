import {
    AtSign,
    Activity,
    Ambulance,
    Archive,
    ArrowLeftRight,
    Baby,
    BadgeDollarSign,
    BadgePercent,
    Bandage,
    Banknote,
    BedDouble,
    BedSingle,
    BellRing,
    BookOpen,
    BookOpenCheck,
    BookX,
    Boxes,
    Briefcase,
    Building2,
    Calculator,
    CalendarClock,
    CalendarDays,
    CalendarHeart,
    CalendarOff,
    ChartColumn,
    ChartLine,
    ChartPie,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    ClipboardPenLine,
    Clock,
    Coins,
    Construction,
    ConciergeBell,
    Contact,
    CreditCard,
    Crown,
    DoorOpen,
    Factory,
    FileCheck2,
    FileClock,
    FileCog,
    FileHeart,
    FileImage,
    FileOutput,
    FileSearch,
    FileSignature,
    FileText,
    Files,
    FlaskConical,
    FolderTree,
    HandCoins,
    HandHeart,
    HandHelping,
    Handshake,
    HeartHandshake,
    HeartPulse,
    History,
    Hospital,
    IdCard,
    Inbox,
    KeyRound,
    Layers,
    LayoutDashboard,
    LayoutTemplate,
    Link2,
    ListChecks,
    ListOrdered,
    ListTodo,
    LockKeyhole,
    LogIn,
    LogOut,
    MapPin,
    Monitor,
    Network,
    NotebookPen,
    NotebookText,
    Package,
    PackageCheck,
    PackageOpen,
    PackageSearch,
    PanelsTopLeft,
    PiggyBank,
    Pill,
    Receipt,
    ReceiptText,
    ScanLine,
    Scissors,
    ScrollText,
    Settings,
    Shield,
    ShieldCheck,
    ShieldPlus,
    ShoppingBasket,
    ShoppingCart,
    Slice,
    SlidersHorizontal,
    Stethoscope,
    Store,
    Syringe,
    Tag,
    Tags,
    TicketPercent,
    TestTube,
    TestTubes,
    Timer,
    ToyBrick,
    Trash2,
    TriangleAlert,
    Truck,
    Type,
    UserRound,
    UserRoundCog,
    Users,
    UsersRound,
    Utensils,
    Vault,
    Wallet,
    Wind,
    Wrench,
} from 'lucide-vue-next';

/**
 * Les modules de « Rôles & permissions » (ADR-178).
 *
 * Un module est ce que l'administrateur appelle un service : Pharmacie,
 * Chirurgie, Caisse. Les sept « domaines » précédents en faisaient trop peu —
 * « Clinique » réunissait à lui seul trente-cinq catégories, de la
 * consultation au bloc opératoire en passant par le laboratoire. L'ordre suit
 * celui du parcours du patient, puis la gestion, puis l'administration.
 */
export const PERMISSION_MODULES = [
    { key: 'reception', label: 'Accueil & patients', description: 'Réception, dossiers, passages et visiteurs', icon: UsersRound },
    { key: 'care', label: 'Soins infirmiers', description: 'Fiches de soins, constantes et demandes du médecin', icon: Bandage },
    { key: 'medicine', label: 'Médecine', description: 'Consultations, diagnostics, ordonnances et orientations', icon: Stethoscope },
    { key: 'hospitalization', label: 'Hospitalisation', description: 'Séjours, lits, régime et notes du jour', icon: BedDouble },
    { key: 'surgery', label: 'Chirurgie & anesthésie', description: 'Bloc opératoire et évaluation anesthésique', icon: Scissors },
    { key: 'maternity', label: 'Maternité', description: 'Suivi, accouchement et nouveau-nés', icon: Baby },
    { key: 'laboratory', label: 'Laboratoire & imagerie', description: 'Analyses, ECG, échographies et résultats', icon: FlaskConical },
    { key: 'pharmacy', label: 'Pharmacie', description: 'Délivrance, stock, achats et fournisseurs', icon: Pill },
    { key: 'finance', label: 'Caisse & facturation', description: 'Factures, encaissements, caisses et créances', icon: Wallet },
    { key: 'tariffs', label: 'Tarifs & mutuelles', description: 'Prestations, tarifs et organismes', icon: Tags },
    { key: 'hr', label: 'Ressources humaines', description: 'Employés, contrats, congés et documents', icon: Briefcase },
    { key: 'logistics', label: 'Logistique & sécurité', description: 'Équipements, stock administratif et gardiennage', icon: Truck },
    { key: 'access', label: 'Utilisateurs & accès', description: 'Comptes, rôles et droits individuels', icon: UserRoundCog },
    { key: 'system', label: 'Administration & système', description: 'Paramètres, audit, corbeille et connexions', icon: Settings },
];

/** Une catégorie que personne n'a encore classée tombe ici, jamais nulle part. */
export const FALLBACK_PERMISSION_MODULE = 'system';

/**
 * Human names for permission categories.
 *
 * A category is the prefix of a permission name (`surgery.view` → `surgery`).
 * That prefix is a code, not a label: shown raw, "analysis_catalog" or
 * "cash_registers" tells an administrator nothing about what they are about
 * to allow or deny. Every category therefore gets the name of the thing it
 * governs, as the clinic says it, and the module it belongs to.
 *
 * The declaration order is the reading order inside a module. A prefix added
 * later without a label still renders readably (see
 * `permissionCategoryLabel`), never as a snake_case code — but a test reads
 * the seeder and refuses any category left without a real name here.
 */
export const PERMISSION_CATEGORIES = {
    // Accueil & patients
    reception: { label: 'Accueil – Réception', module: 'reception', icon: ConciergeBell },
    patients: { label: 'Dossiers patients', module: 'reception', icon: IdCard },
    episodes: { label: 'Passages (admissions)', module: 'reception', icon: DoorOpen },
    visitors: { label: 'Visiteurs', module: 'reception', icon: Users },
    patient_coverages: { label: 'Couvertures mutuelle du patient', module: 'reception', icon: ShieldCheck },
    patient_coverage_documents: { label: 'Justificatifs de mutuelle', module: 'reception', icon: FileCheck2 },
    patient_staff_links: { label: 'Lien patient – membre du personnel', module: 'reception', icon: Link2 },
    patient_vip: { label: 'Seuils des patients VIP', module: 'reception', icon: Crown },
    address_entries: { label: 'Adresses et localités', module: 'reception', icon: MapPin },

    // Soins infirmiers
    care: { label: 'Soins infirmiers', module: 'care', icon: Syringe },
    vitals: { label: 'Constantes vitales', module: 'care', icon: HeartPulse },
    care_orders: { label: 'Demandes de soins (médecin → Soins)', module: 'care', icon: ClipboardList },
    care_consumables: { label: 'Consommables utilisés aux Soins', module: 'care', icon: Package },
    treatment_journal: { label: 'Journal de traitement', module: 'care', icon: NotebookPen },

    // Médecine
    consultations: { label: 'Consultations médicales', module: 'medicine', icon: Stethoscope },
    medical_record: { label: 'Dossier médical', module: 'medicine', icon: FileHeart },
    diagnoses: { label: 'Diagnostics', module: 'medicine', icon: ClipboardCheck },
    diagnostic_catalog: { label: 'Liste des diagnostics', module: 'medicine', icon: ListChecks },
    prescriptions: { label: 'Ordonnances', module: 'medicine', icon: ScrollText },
    medical_orders: { label: 'Ordres médicaux', module: 'medicine', icon: ClipboardPenLine },
    clinical_protocols: { label: 'Protocoles thérapeutiques', module: 'medicine', icon: BookOpenCheck },
    // ADR-151 — la catégorie nomme les modules où le droit agit : le rail se
    // cherche aussi par ce que l'écran appelle le bouton (« sortie
    // d'hospitalisation »), pas seulement par le nom du module d'origine.
    medical_discharge: { label: 'Sortie médicale (consultation, hospitalisation, pédiatrie)', module: 'medicine', icon: LogOut },
    transfer: { label: 'Référence et transfert', module: 'medicine', icon: ArrowLeftRight },
    transfers: { label: 'Transferts : départ des patients', module: 'medicine', icon: Ambulance },
    pediatrics: { label: 'Orientation Pédiatrie', module: 'medicine', icon: ToyBrick },
    death_records: { label: 'Registre des décès', module: 'medicine', icon: BookX },

    // Hospitalisation
    hospitalization: { label: 'Hospitalisation (demande, séjour, export)', module: 'hospitalization', icon: Hospital },
    // ADR-162 — la note quotidienne du séjour, écrite par le médecin, lue par les Soins.
    hospital_notes: { label: 'Notes quotidiennes du séjour', module: 'hospitalization', icon: NotebookText },
    hospital_diet: { label: 'Fiche de régime', module: 'hospitalization', icon: Utensils },
    hospital_beds: { label: 'Services, chambres et lits', module: 'hospitalization', icon: BedSingle },

    // Chirurgie & anesthésie
    surgery: { label: 'Chirurgie (Bloc opératoire)', module: 'surgery', icon: Scissors },
    anesthesia: { label: 'Anesthésie', module: 'surgery', icon: Wind },

    // Maternité
    maternity: { label: 'Maternité', module: 'maternity', icon: HandHeart },
    newborns: { label: 'Nouveau-nés (dossier chez sa mère)', module: 'maternity', icon: Baby },

    // Laboratoire & imagerie
    paraclinical_requests: { label: 'Espace Demandes d’examens', module: 'laboratory', icon: Inbox },
    laboratory_orders: { label: 'Demandes d’analyses', module: 'laboratory', icon: TestTube },
    laboratory_results: { label: 'Résultats d’analyses', module: 'laboratory', icon: TestTubes },
    analysis_catalog: { label: 'Catalogue des analyses', module: 'laboratory', icon: FlaskConical },
    imaging_orders: { label: 'Demandes d’imagerie (ECG, échographie)', module: 'laboratory', icon: ScanLine },
    imaging_results: { label: 'Résultats d’imagerie', module: 'laboratory', icon: FileImage },
    imaging_templates: { label: 'Feuilles de compte rendu d’imagerie', module: 'laboratory', icon: LayoutTemplate },

    // Pharmacie
    pharmacy: { label: 'Délivrance et vente comptoir', module: 'pharmacy', icon: ShoppingBasket },
    medicines: { label: 'Fiches médicaments', module: 'pharmacy', icon: Pill },
    medicine_categories: { label: 'Catégories de médicaments', module: 'pharmacy', icon: FolderTree },
    stock: { label: 'Stock et lots de médicaments', module: 'pharmacy', icon: Boxes },
    purchase_orders: { label: 'Commandes fournisseurs', module: 'pharmacy', icon: ShoppingCart },
    goods_receipts: { label: 'Réceptions de marchandises', module: 'pharmacy', icon: PackageCheck },
    supplier_invoices: { label: 'Factures fournisseurs', module: 'pharmacy', icon: ReceiptText },
    medicine_suppliers: { label: 'Fournisseurs de médicaments', module: 'pharmacy', icon: Factory },
    supplier_catalogs: { label: 'Catalogues fournisseurs', module: 'pharmacy', icon: BookOpen },
    medicine_supplier_offers: { label: 'Prix d’achat des fournisseurs', module: 'pharmacy', icon: BadgeDollarSign },

    // Caisse & facturation
    billing: { label: 'Factures', module: 'finance', icon: FileText },
    payments: { label: 'Encaissements', module: 'finance', icon: Banknote },
    receipts: { label: 'Reçus de paiement', module: 'finance', icon: Receipt },
    cash: { label: 'Session de caisse (ouverture / clôture)', module: 'finance', icon: Vault },
    cash_registers: { label: 'Postes de caisse', module: 'finance', icon: Calculator },
    payment_methods: { label: 'Modes de paiement', module: 'finance', icon: CreditCard },
    debts: { label: 'Créances et sorties avec dette', module: 'finance', icon: HandCoins },
    discounts: { label: 'Remises (facture, patient)', module: 'finance', icon: BadgePercent },
    discount_coupons: { label: 'Coupons de remise', module: 'finance', icon: TicketPercent },
    reports: { label: 'Rapports financiers', module: 'finance', icon: ChartColumn },

    // Tarifs & mutuelles
    catalog: { label: 'Prestations et tarifs', module: 'tariffs', icon: Tags },
    mutual_organizations: { label: 'Mutuelles et taux de couverture', module: 'tariffs', icon: ShieldPlus },
    partner_organizations: { label: 'Organismes partenaires', module: 'tariffs', icon: Handshake },

    // Ressources humaines
    employees: { label: 'Dossiers employés', module: 'hr', icon: Contact },
    contracts: { label: 'Contrats de travail', module: 'hr', icon: FileSignature },
    attendance: { label: 'Présences', module: 'hr', icon: Clock },
    leave: { label: 'Congés et permissions', module: 'hr', icon: CalendarOff },
    planning: { label: 'Planning du personnel', module: 'hr', icon: CalendarDays },
    hr_documents: { label: 'Pièces du dossier employé', module: 'hr', icon: Files },
    document_templates: { label: 'Modèles de documents', module: 'hr', icon: FileCog },
    generated_documents: { label: 'Documents générés (attestations, contrats…)', module: 'hr', icon: FileOutput },
    staff_block_credits: { label: 'Crédit Bloc du personnel', module: 'hr', icon: Coins },
    hr_reports: { label: 'Rapports RH', module: 'hr', icon: ChartPie },
    hr_settings: { label: 'Paramètres RH (services, fonctions…)', module: 'hr', icon: SlidersHorizontal },
    professional_emails: { label: 'Adresses email professionnelles', module: 'hr', icon: AtSign },

    // Logistique & sécurité
    logistics: { label: 'Logistique', module: 'logistics', icon: Truck },
    equipment: { label: 'Équipements', module: 'logistics', icon: Monitor },
    administrative_stock: { label: 'Stock administratif (fournitures)', module: 'logistics', icon: Archive },
    guarding: { label: 'Gardiennage (entrées et sorties)', module: 'logistics', icon: Shield },

    // Utilisateurs & accès
    users: { label: 'Comptes utilisateurs', module: 'access', icon: UserRound },
    roles: { label: 'Rôles', module: 'access', icon: KeyRound },
    permissions: { label: 'Droits individuels', module: 'access', icon: LockKeyhole },

    // Administration & système
    super_admin: { label: 'Portail Super Administration', module: 'system', icon: LayoutDashboard },
    sites: { label: 'Sites de la clinique', module: 'system', icon: Building2 },
    settings: { label: 'Paramètres de l’application', module: 'system', icon: Settings },
    app_maintenance: { label: 'Maintenance du site', module: 'system', icon: Construction },
    audit: { label: 'Journal d’audit', module: 'system', icon: History },
    trash: { label: 'Corbeille', module: 'system', icon: Trash2 },
    api: { label: 'Connexions API entre sites', module: 'system', icon: Network },
};

/**
 * Les sous-ressources — `catalog.items`, `surgery.report` — deviennent chacune
 * une ligne de la grille, à côté de leur catégorie. Sans nom, « Report » ou
 * « Items » se liraient comme des codes ; ceux-ci sont donc nommés.
 */
export const PERMISSION_RESOURCES = {
    'patients.medical_history': 'Antécédents et allergies',
    'episodes.settlement': 'Passages à régler (sorties)',

    'surgery.intervention': 'Intervention',
    'surgery.preoperative': 'Bilan préopératoire',
    'surgery.preparation': 'Préparation du bloc',
    'surgery.care': 'Soins peropératoires',
    'surgery.report': 'Compte rendu opératoire',
    'surgery.complications': 'Complications',
    'surgery.consumables': 'Consommables du bloc',
    'surgery.postoperative_care': 'Soins postopératoires',
    'surgery.discharge': 'Sortie de chirurgie',

    'maternity.prenatal': 'Suivi prénatal',
    'maternity.labor': 'Travail et surveillance',
    'maternity.delivery': 'Accouchement',
    'maternity.newborn': 'Nouveau-nés (fiche Maternité)',
    'maternity.procedures': 'Actes de Maternité',
    'newborns.medical_record': 'Dossier médical du nouveau-né',
    'newborns.patient': 'Dossier patient du nouveau-né',

    'pharmacy.dispense': 'Délivrance (ticket et facture)',
    'pharmacy.counter_sales': 'Vente au comptoir',
    'pharmacy.reports': 'Rapports de pharmacie',
    'medicines.name': 'Nom de vente',
    'medicines.sale_price': 'Prix de vente',
    'stock.lots': 'Lots de médicaments',
    'stock.availability': 'Disponibilité des médicaments',
    'stock.expiration': 'Péremptions',
    'stock.alerts': 'Alertes de stock',
    'stock.cost': 'Prix d’achat',

    'reports.financial': 'Rapports financiers par site',
    'catalog.items': 'Référentiel des prestations et produits',
    'catalog.tariffs': 'Tarifs Standard et Mutuelle',

    'equipment.maintenance': 'Maintenance des équipements',
    'guarding.entries': 'Journal des entrées et sorties',
    'guarding.reports': 'Rapports de gardiennage',

    'super_admin.portal': 'Accès au portail',
};

/**
 * L'icône d'une sous-ressource, quand elle en a une à elle (ADR-178). Sans elle,
 * la ligne reprend l'icône de sa catégorie, puis celle de son module : une
 * fonctionnalité ajoutée plus tard n'apparaît jamais sans icône.
 */
export const PERMISSION_RESOURCE_ICONS = {
    'patients.medical_history': FileClock,
    'episodes.settlement': CircleDollarSign,
    'surgery.intervention': Slice,
    'surgery.preoperative': ListTodo,
    'surgery.preparation': PackageOpen,
    'surgery.care': Syringe,
    'surgery.report': FileText,
    'surgery.complications': TriangleAlert,
    'surgery.consumables': Package,
    'surgery.postoperative_care': Activity,
    'surgery.discharge': LogOut,
    'maternity.prenatal': CalendarHeart,
    'maternity.labor': Timer,
    'maternity.delivery': HeartHandshake,
    'maternity.newborn': Baby,
    'maternity.procedures': ClipboardList,
    'newborns.medical_record': FileHeart,
    'newborns.patient': IdCard,
    'pharmacy.dispense': HandHelping,
    'pharmacy.counter_sales': Store,
    'pharmacy.reports': ChartColumn,
    'medicines.name': Type,
    'medicines.sale_price': Tag,
    'stock.lots': Layers,
    'stock.availability': PackageSearch,
    'stock.expiration': CalendarClock,
    'stock.alerts': BellRing,
    'stock.cost': PiggyBank,
    'reports.financial': ChartLine,
    'catalog.items': ListOrdered,
    'catalog.tariffs': BadgePercent,
    'equipment.maintenance': Wrench,
    'guarding.entries': LogIn,
    'guarding.reports': FileSearch,
    'super_admin.portal': PanelsTopLeft,
};

/** "medicine_suppliers" → "Medicine suppliers": readable even without a label. */
const humanize = (key) => {
    const text = String(key ?? '').replace(/[_.]+/g, ' ').trim();

    return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Autres';
};

export const permissionCategoryLabel = (key) => PERMISSION_CATEGORIES[key]?.label ?? humanize(key);

export const permissionCategoryModule = (key) => PERMISSION_CATEGORIES[key]?.module ?? FALLBACK_PERMISSION_MODULE;

/** L'icône d'une catégorie ; une catégorie non classée reprend celle de son module. */
export const permissionCategoryIcon = (key) => PERMISSION_CATEGORIES[key]?.icon
    ?? PERMISSION_MODULES.find((module) => module.key === permissionCategoryModule(key))?.icon
    ?? PERMISSION_MODULES.find((module) => module.key === FALLBACK_PERMISSION_MODULE).icon;

/** `surgery.report` → son icône propre, sinon celle de `surgery`, sinon celle du module. */
export const permissionResourceIcon = (key) => PERMISSION_RESOURCE_ICONS[key]
    ?? permissionCategoryIcon(String(key ?? '').split('.')[0]);

export const permissionModule = (key) => PERMISSION_MODULES.find((module) => module.key === key)
    ?? PERMISSION_MODULES.find((module) => module.key === FALLBACK_PERMISSION_MODULE);

/** `surgery.report` → « Compte rendu opératoire » ; une catégorie → son libellé. */
export const permissionResourceLabel = (key) => {
    if (! String(key ?? '').includes('.')) return permissionCategoryLabel(key);

    return PERMISSION_RESOURCES[key] ?? humanize(String(key).split('.').slice(1).join(' '));
};

/** L'ordre de lecture déclaré ci-dessus ; une catégorie inconnue vient après. */
export const permissionCategoryOrder = (key) => {
    const index = Object.keys(PERMISSION_CATEGORIES).indexOf(key);

    return index === -1 ? Number.MAX_SAFE_INTEGER : index;
};

export const permissionResourceOrder = (key) => {
    const index = Object.keys(PERMISSION_RESOURCES).indexOf(key);

    return index === -1 ? Number.MAX_SAFE_INTEGER : index;
};
