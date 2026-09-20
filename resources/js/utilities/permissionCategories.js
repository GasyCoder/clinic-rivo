/**
 * Human names for permission categories.
 *
 * A category is the prefix of a permission name (`surgery.view` → `surgery`).
 * That prefix is a code, not a label: shown raw, "analysis_catalog" or
 * "cash_registers" tells an administrator nothing about what they are about
 * to allow or deny. Every category therefore gets the name of the thing it
 * governs, as the clinic says it, and a domain so the list reads by area of
 * work instead of by alphabet.
 *
 * A prefix added later without a label still renders readably (see
 * `permissionCategoryLabel`), never as a snake_case code.
 */
export const PERMISSION_DOMAINS = [
    { key: 'patient', label: 'Parcours patient' },
    { key: 'clinical', label: 'Clinique' },
    { key: 'pharmacy', label: 'Pharmacie' },
    { key: 'finance', label: 'Caisse et facturation' },
    { key: 'hr', label: 'Ressources humaines' },
    { key: 'logistics', label: 'Logistique et accueil' },
    { key: 'admin', label: 'Administration du système' },
];

export const PERMISSION_CATEGORIES = {
    // Parcours patient
    reception: { label: 'Accueil – Réception', domain: 'patient' },
    patients: { label: 'Dossiers patients', domain: 'patient' },
    patient_vip: { label: 'Seuils des patients VIP', domain: 'patient' },
    episodes: { label: 'Passages (admissions)', domain: 'patient' },
    patient_coverages: { label: 'Couvertures mutuelle du patient', domain: 'patient' },
    patient_coverage_documents: { label: 'Justificatifs de mutuelle', domain: 'patient' },
    patient_staff_links: { label: 'Lien patient – membre du personnel', domain: 'patient' },
    address_entries: { label: 'Adresses et localités', domain: 'patient' },
    visitors: { label: 'Visiteurs', domain: 'patient' },

    // Clinique
    care: { label: 'Soins infirmiers', domain: 'clinical' },
    vitals: { label: 'Constantes vitales', domain: 'clinical' },
    care_orders: { label: 'Demandes de soins (médecin → Soins)', domain: 'clinical' },
    care_consumables: { label: 'Consommables utilisés aux Soins', domain: 'clinical' },
    consultations: { label: 'Consultations médicales', domain: 'clinical' },
    medical_record: { label: 'Dossier médical', domain: 'clinical' },
    diagnoses: { label: 'Diagnostics', domain: 'clinical' },
    diagnostic_catalog: { label: 'Liste des diagnostics', domain: 'clinical' },
    prescriptions: { label: 'Ordonnances', domain: 'clinical' },
    medical_orders: { label: 'Ordres médicaux', domain: 'clinical' },
    // ADR-151 — la catégorie nomme les modules où le droit agit : le rail se
    // cherche aussi par ce que l'écran appelle le bouton (« sortie
    // d'hospitalisation »), pas seulement par le nom du module d'origine.
    medical_discharge: { label: 'Sortie médicale (consultation, hospitalisation, pédiatrie)', domain: 'clinical' },
    hospitalization: { label: 'Demande d’hospitalisation', domain: 'clinical' },
    transfer: { label: 'Référence et transfert', domain: 'clinical' },
    pediatrics: { label: 'Orientation Pédiatrie', domain: 'clinical' },
    maternity: { label: 'Maternité', domain: 'clinical' },
    newborns: { label: 'Nouveau-nés (dossier chez sa mère)', domain: 'clinical' },
    surgery: { label: 'Chirurgie (Bloc opératoire)', domain: 'clinical' },
    anesthesia: { label: 'Anesthésie', domain: 'clinical' },
    paraclinical_requests: { label: 'Espace Demandes d’examens', domain: 'clinical' },
    laboratory_orders: { label: 'Demandes d’analyses', domain: 'clinical' },
    laboratory_results: { label: 'Résultats d’analyses', domain: 'clinical' },
    analysis_catalog: { label: 'Catalogue des analyses', domain: 'clinical' },
    imaging_orders: { label: 'Demandes d’imagerie (ECG, échographie)', domain: 'clinical' },
    imaging_results: { label: 'Résultats d’imagerie', domain: 'clinical' },

    // Pharmacie
    pharmacy: { label: 'Délivrance et vente comptoir', domain: 'pharmacy' },
    medicines: { label: 'Fiches médicaments', domain: 'pharmacy' },
    medicine_categories: { label: 'Catégories de médicaments', domain: 'pharmacy' },
    medicine_suppliers: { label: 'Fournisseurs de médicaments', domain: 'pharmacy' },
    stock: { label: 'Stock et lots de médicaments', domain: 'pharmacy' },

    // Caisse et facturation
    billing: { label: 'Factures', domain: 'finance' },
    payments: { label: 'Encaissements', domain: 'finance' },
    receipts: { label: 'Reçus de paiement', domain: 'finance' },
    debts: { label: 'Créances et sorties avec dette', domain: 'finance' },
    cash: { label: 'Session de caisse (ouverture / clôture)', domain: 'finance' },
    cash_registers: { label: 'Postes de caisse', domain: 'finance' },
    payment_methods: { label: 'Modes de paiement', domain: 'finance' },
    catalog: { label: 'Prestations et tarifs', domain: 'finance' },
    mutual_organizations: { label: 'Mutuelles et taux de couverture', domain: 'finance' },
    partner_organizations: { label: 'Organismes partenaires', domain: 'finance' },
    staff_block_credits: { label: 'Crédit Bloc du personnel', domain: 'finance' },
    reports: { label: 'Rapports financiers', domain: 'finance' },

    // Ressources humaines
    employees: { label: 'Dossiers employés', domain: 'hr' },
    contracts: { label: 'Contrats de travail', domain: 'hr' },
    attendance: { label: 'Présences', domain: 'hr' },
    leave: { label: 'Congés et permissions', domain: 'hr' },
    planning: { label: 'Planning du personnel', domain: 'hr' },
    hr_documents: { label: 'Pièces du dossier employé', domain: 'hr' },
    hr_reports: { label: 'Rapports RH', domain: 'hr' },
    hr_settings: { label: 'Paramètres RH (services, fonctions…)', domain: 'hr' },
    document_templates: { label: 'Modèles de documents', domain: 'hr' },
    generated_documents: { label: 'Documents générés (attestations, contrats…)', domain: 'hr' },

    // Logistique et accueil
    logistics: { label: 'Logistique', domain: 'logistics' },
    equipment: { label: 'Équipements', domain: 'logistics' },
    administrative_stock: { label: 'Stock administratif (fournitures)', domain: 'logistics' },
    guarding: { label: 'Gardiennage (entrées et sorties)', domain: 'logistics' },

    // Administration du système
    users: { label: 'Comptes utilisateurs', domain: 'admin' },
    roles: { label: 'Rôles', domain: 'admin' },
    permissions: { label: 'Droits individuels', domain: 'admin' },
    trash: { label: 'Corbeille', domain: 'admin' },
    settings: { label: 'Paramètres de l’application', domain: 'admin' },
    audit: { label: 'Journal d’audit', domain: 'admin' },
    api: { label: 'Connexions API entre sites', domain: 'admin' },
    sites: { label: 'Sites de la clinique', domain: 'admin' },
    super_admin: { label: 'Portail Super Administration', domain: 'admin' },
};

/** "medicine_suppliers" → "Medicine suppliers": readable even without a label. */
const humanize = (key) => {
    const text = String(key ?? '').replace(/[_.]+/g, ' ').trim();

    return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Autres';
};

export const permissionCategoryLabel = (key) => PERMISSION_CATEGORIES[key]?.label ?? humanize(key);

export const permissionCategoryDomain = (key) => PERMISSION_CATEGORIES[key]?.domain ?? 'admin';
