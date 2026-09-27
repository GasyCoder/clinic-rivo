/**
 * Comment nommer le mode financier d'un passage, à un seul endroit.
 *
 * Miroir de `App\Enums\EpisodeFinancialMode::label()`. Le même mode portait
 * jusqu'ici trois noms selon l'écran — « Patient » côté serveur,
 * « Sans mutuelle » en en-tête clinique, « Paiement personnel » à la
 * Réception — pour une seule et même colonne `episodes.financial_mode`.
 *
 * À ne pas confondre avec la **grille tarifaire** : « Sans mutuelle » reste
 * le nom d'affichage de la catégorie `STANDARD` (ADR-031), c'est-à-dire le
 * barème appliqué, pas qui le paie. Les deux notions se recoupent souvent
 * mais pas toujours — un passage `STAFF` ou `PARTNER` est lui aussi facturé
 * au barème Sans mutuelle.
 */
export const FINANCIAL_MODE_LABELS = {
    SELF: 'Standard',
    MUTUAL: 'Mutuelle',
    STAFF: 'Personnel',
    PARTNER: 'Partenaire',
};

export function financialModeLabel(mode) {
    return FINANCIAL_MODE_LABELS[mode] ?? null;
}
