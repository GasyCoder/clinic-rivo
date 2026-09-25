import { BadgePercent, Baby, Coins, Globe, Hash, Landmark, LayoutTemplate, Palette, PenLine, SearchX, SlidersHorizontal } from 'lucide-vue-next';

/**
 * Les modules des paramètres de l'application (ADR-191, amendement du 2026-09-25) :
 * chacun a sa page, `/super-admin/settings/{id}`, rangée dans la colonne de gauche.
 * Écrits une fois : le menu des modules et le serveur (`AppSettingsController::SECTIONS`,
 * vérifié par test) lisent cette liste ; le premier s'ouvre sur « Paramètres ».
 *
 * `fields` : les champs du formulaire que le module règle — ce qui compte comme
 * modifié sur sa page.
 */
export const SETTINGS_GROUPS = Object.freeze([
    { id: 'apparence', label: 'Apparence', description: 'Ce que chacun voit : nom, couleurs, affichage et disposition des écrans.' },
    { id: 'dossiers', label: 'Patients & personnel', description: 'Les numéros attribués et le formulaire d’un nouveau patient.' },
    { id: 'etablissement', label: 'Établissement & documents', description: 'Ce qui s’imprime sur les factures, reçus et documents, et les remises.' },
    { id: 'confidentialite', label: 'Confidentialité', description: 'Ce que l’extérieur peut voir de l’application.' },
]);

export const SETTINGS_SECTIONS = Object.freeze([
    {
        id: 'identite', group: 'apparence', label: 'Identité', icon: Globe,
        description: 'Nom de l’application, devise, logo et icône.',
        fields: ['app_name', 'app_tagline'],
    },
    {
        id: 'theme', group: 'apparence', label: 'Thème', icon: Palette,
        description: 'Couleurs du mode clair et du mode sombre.',
        fields: ['theme_preset', 'primary_color', 'light_background', 'light_foreground', 'dark_primary_color', 'dark_background', 'dark_foreground'],
    },
    {
        id: 'avance', group: 'apparence', label: 'Affichage avancé', icon: SlidersHorizontal,
        description: 'Taille du texte, densité, arrondis, animations et contraste.',
        fields: ['ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast'],
    },
    {
        id: 'ecrans', group: 'apparence', label: 'Écrans & modèles', icon: LayoutTemplate,
        description: 'Pages de connexion, « Mon profil » et image de fond.',
        fields: ['auth_template', 'profile_template'],
    },
    {
        id: 'numerotation', group: 'dossiers', label: 'Numérotation', icon: Hash,
        description: 'Numéros de patient et de passage, matricule des employés.',
        fields: [
            'patient_number_prefix', 'patient_number_year', 'patient_number_digits', 'patient_number_separator',
            'patient_number_reset', 'episode_number_digits', 'employee_number_prefix', 'employee_number_separator', 'employee_number_digits',
        ],
    },
    {
        id: 'ages', group: 'dossiers', label: 'Âges des patients', icon: Baby,
        description: 'Tranches bébé, enfant et adulte du formulaire patient.',
        fields: ['baby_max_age', 'child_max_age'],
    },
    {
        id: 'monnaie', group: 'etablissement', label: 'Monnaie', icon: Coins,
        description: 'Écriture de l’Ariary sur les écrans et les documents.',
        fields: ['currency_label', 'currency_position', 'currency_decimals'],
    },
    {
        id: 'remises', group: 'etablissement', label: 'Remises', icon: BadgePercent,
        description: 'Remise du personnel et coupons de remise.',
        fields: ['staff_discount_type', 'staff_discount_value'],
    },
    {
        id: 'legal', group: 'etablissement', label: 'Identité légale', icon: Landmark,
        description: 'NIF, STAT, adresse, contacts et compte bancaire.',
        fields: ['legal_nif', 'legal_stat', 'legal_address', 'legal_phone', 'legal_email', 'bank_name', 'bank_account'],
    },
    {
        id: 'direction', group: 'etablissement', label: 'Direction', icon: PenLine,
        description: 'Directeur général et signature des documents RH.',
        fields: ['director_name', 'director_title'],
    },
    {
        id: 'visibilite', group: 'confidentialite', label: 'Moteurs de recherche', icon: SearchX,
        description: 'Masquer l’application de Google, Bing et des autres.',
        fields: ['search_engines_hidden'],
    },
]);

export const SETTINGS_SECTION_IDS = Object.freeze(SETTINGS_SECTIONS.map((section) => section.id));

export const settingsSection = (id) => SETTINGS_SECTIONS.find((section) => section.id === id) ?? null;

/** Les modules d'un groupe, dans l'ordre de la liste. */
export const sectionsOf = (groupId) => SETTINGS_SECTIONS.filter((section) => section.group === groupId);

/** L'adresse d'un module (ou de l'accueil), pour un site donné. */
export const settingsUrl = (sectionId = null, siteCode = '') => {
    const path = sectionId ? `/super-admin/settings/${sectionId}` : '/super-admin/settings';

    return siteCode ? `${path}?site=${encodeURIComponent(siteCode)}` : path;
};
