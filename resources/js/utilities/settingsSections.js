import { BadgePercent, Baby, BriefcaseBusiness, Coins, Construction, FlaskConical, Globe, Hash, IdCard, Landmark, LayoutTemplate, Palette, PenLine, SearchX, SlidersHorizontal, Sparkles } from 'lucide-vue-next';
import { BADGE_FIELDS } from './employeeBadge.js';
import { LAB_REPORT_FIELDS } from './labReportDesign.js';

/**
 * Les réglages de l'application (ADR-237) : chacun appartient à un seul module
 * métier et possède une seule route canonique, rangée dans la colonne de gauche.
 * Écrits une fois : le menu des modules et le serveur (`AppSettingsController::SECTIONS`,
 * vérifié par test) lisent cette liste ; le premier s'ouvre sur « Paramètres ».
 *
 * `fields` : les champs du formulaire que le module règle — ce qui compte comme
 * modifié sur sa page.
 */
export const SETTINGS_GROUPS = Object.freeze([
    { id: 'apparence', label: 'Apparence', description: 'Ce que chacun voit : nom, couleurs, affichage et disposition des écrans.' },
    { id: 'dossiers', label: 'Patients & personnel', description: 'Les numéros attribués, le formulaire d’un nouveau patient et le badge du personnel.' },
    { id: 'etablissement', label: 'Établissement & documents', description: 'Ce qui s’imprime sur les factures, reçus et documents, et les remises.' },
    { id: 'confidentialite', label: 'Confidentialité', description: 'Ce que l’extérieur peut voir de l’application.' },
    { id: 'exploitation', label: 'Exploitation', description: 'La disponibilité de chaque site pour ses utilisateurs.' },
    { id: 'ia', label: 'Intelligence artificielle', description: 'L’assistant qui aide à utiliser le logiciel.' },
]);

/**
 * Un réglage appartient à un seul espace métier. `system` est le seul espace
 * qui reste sous /settings ; les autres ont une adresse canonique dans leur
 * module. Les anciennes adresses sont uniquement des redirections serveur.
 */
export const SETTINGS_CONTEXTS = Object.freeze({
    system: {
        label: 'Apparence & système',
        description: 'Identité visuelle, affichage, confidentialité et services techniques du portail et des sites.',
    },
    patients: {
        label: 'Configuration des patients',
        description: 'Règles appliquées aux nouveaux dossiers patients et à leurs passages.',
    },
    finance: {
        label: 'Configuration financière',
        description: 'Présentation des montants et règles de remise propres à chaque site.',
    },
    hr: {
        label: 'Configuration du personnel',
        description: 'Matricules, badges et identité de la direction sur les documents RH.',
    },
    laboratory: {
        label: 'Configuration du laboratoire',
        description: 'Présentation des comptes rendus d’analyses de chaque site.',
    },
    organization: {
        label: 'Configuration de l’établissement',
        description: 'Identité légale et disponibilité opérationnelle de chaque site.',
    },
});

export const SETTINGS_SECTIONS = Object.freeze([
    {
        id: 'identite', owner: 'system', group: 'apparence', label: 'Identité', icon: Globe,
        description: 'Nom de l’application, devise, logo et icône.',
        fields: ['app_name', 'app_tagline'],
    },
    {
        id: 'theme', owner: 'system', group: 'apparence', label: 'Thème', icon: Palette,
        description: 'Police des textes et couleurs du mode clair et du mode sombre.',
        fields: ['ui_font_family', 'theme_preset', 'primary_color', 'light_background', 'light_foreground', 'dark_primary_color', 'dark_background', 'dark_foreground'],
    },
    {
        id: 'avance', owner: 'system', group: 'apparence', label: 'Affichage avancé', icon: SlidersHorizontal,
        description: 'Taille du texte, densité, arrondis, animations et contraste.',
        fields: ['ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast'],
    },
    {
        id: 'ecrans', owner: 'system', group: 'apparence', label: 'Écrans & modèles', icon: LayoutTemplate,
        description: 'Pages de connexion, « Mon profil » et image de fond.',
        fields: ['auth_template', 'profile_template'],
    },
    {
        id: 'numerotation', owner: 'patients', group: 'dossiers', label: 'Numérotation', icon: Hash,
        description: 'Numéros des nouveaux patients et de leurs passages.',
        fields: [
            'patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator',
            'patient_number_reset', 'episode_number_digits',
        ],
    },
    {
        id: 'ages', owner: 'patients', group: 'dossiers', label: 'Âges des patients', icon: Baby,
        description: 'Tranches bébé, enfant et adulte du formulaire patient.',
        fields: ['baby_max_age', 'child_max_age'],
    },
    {
        id: 'matricules', owner: 'hr', group: 'dossiers', label: 'Matricules', icon: BriefcaseBusiness,
        description: 'Format du matricule proposé à la création d’un employé ou d’un stagiaire.',
        fields: ['employee_number_prefix', 'employee_number_separator', 'employee_number_digits', 'intern_number_prefix'],
    },
    {
        // ADR-209 — un seul modèle pour tout le personnel, dont tout l'aspect se règle (amendement du 2026-09-27).
        id: 'badges', owner: 'hr', group: 'dossiers', label: 'Badge du personnel', icon: IdCard,
        description: 'Couleurs, textes, polices, disposition et impression du badge des employés et stagiaires.',
        fields: [...BADGE_FIELDS],
    },
    {
        id: 'monnaie', owner: 'finance', group: 'etablissement', label: 'Monnaie', icon: Coins,
        description: 'Écriture de l’Ariary sur les écrans et les documents.',
        fields: ['currency_label', 'currency_position', 'currency_decimals'],
    },
    {
        id: 'remises', owner: 'finance', group: 'etablissement', label: 'Remises', icon: BadgePercent,
        description: 'Remise du personnel et coupons de remise.',
        fields: ['staff_discount_type', 'staff_discount_value'],
    },
    {
        id: 'legal', owner: 'organization', group: 'etablissement', label: 'Identité légale', icon: Landmark,
        description: 'NIF, STAT, adresse, contacts et compte bancaire.',
        fields: ['legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account'],
    },
    {
        id: 'direction', owner: 'hr', group: 'etablissement', label: 'Direction', icon: PenLine,
        description: 'Directeur général et signature des documents RH.',
        fields: ['director_name', 'director_title'],
    },
    {
        // ADR-223 — l'aspect du compte rendu d'analyses (PDF) ; les résultats ne se règlent jamais ici.
        id: 'compte-rendu', owner: 'laboratory', group: 'etablissement', label: 'Compte rendu d’analyses', icon: FlaskConical,
        description: 'Modèle, couleurs, en-tête, QR code, signature et bas de page du PDF des résultats.',
        fields: [...LAB_REPORT_FIELDS],
    },
    {
        id: 'visibilite', owner: 'system', group: 'confidentialite', label: 'Moteurs de recherche', icon: SearchX,
        description: 'Masquer l’application de Google, Bing et des autres.',
        fields: ['search_engines_hidden'],
    },
    {
        // ADR-193 — une commande qui part tout de suite, avec ses propres droits :
        // aucun champ du formulaire commun, donc pas de « Enregistrer » en pied.
        id: 'maintenance', owner: 'organization', group: 'exploitation', label: 'Maintenance', icon: Construction,
        description: 'Fermer un site pour une intervention, avec un message.',
        fields: [],
    },
    {
        // ADR-222 — ses propres réglages, ses propres droits (`ai_settings.*`) et sa clé :
        // aucun champ du formulaire commun, donc pas de « Enregistrer » en pied.
        id: 'assistant', owner: 'system', group: 'ia', label: 'Assistant IA', icon: Sparkles,
        description: 'Fournisseur, modèle, clé d’API, limites et consommation.',
        fields: [],
    },
]);

export const SETTINGS_SECTION_IDS = Object.freeze(SETTINGS_SECTIONS.map((section) => section.id));

export const settingsSection = (id) => SETTINGS_SECTIONS.find((section) => section.id === id) ?? null;

/** Les modules d'un groupe, dans l'ordre de la liste. */
export const sectionsOf = (groupId) => SETTINGS_SECTIONS.filter((section) => section.group === groupId);

export const sectionsOfContext = (context) => SETTINGS_SECTIONS.filter((section) => section.owner === context);

/** L'adresse d'un module (ou de l'accueil), pour un site donné. */
export const settingsUrl = (sectionId = null, siteCode = '') => {
    const section = settingsSection(sectionId);
    if (! section) return '/super-admin/settings';

    if (['patients', 'organization'].includes(section.owner) && siteCode) {
        return `/super-admin/sites/${encodeURIComponent(siteCode)}/${section.owner}/settings/${section.id}`;
    }

    const roots = {
        system: '/super-admin/settings',
        finance: '/super-admin/finance/settings',
        hr: '/super-admin/human-resources/settings',
        laboratory: '/super-admin/laboratory/settings',
    };
    const path = `${roots[section.owner] ?? '/super-admin/settings'}/${section.id}`;

    return siteCode ? `${path}?site=${encodeURIComponent(siteCode)}` : path;
};
