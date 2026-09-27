import fs from 'node:fs';
import path from 'node:path';

/**
 * ADR-204 — le dossier Maternité est une page qui orchestre des composants
 * (`Components/Maternity`, ses étapes dans `Steps/`). Une règle d'écran se
 * vérifie donc sur l'ensemble : la page et ce qu'elle monte.
 */
const COMPONENTS = 'resources/js/Components/Maternity';

const vueFiles = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) return vueFiles(full);

    return entry.name.endsWith('.vue') ? [full] : [];
}).sort();

export const maternityShowPage = () => fs.readFileSync('resources/js/Pages/Maternity/Show.vue', 'utf8');

export const maternityComponentFiles = () => vueFiles(COMPONENTS);

/** La page et tous ses composants, dans un ordre stable. */
export const maternityBundle = () => [
    maternityShowPage(),
    ...maternityComponentFiles().map((file) => fs.readFileSync(file, 'utf8')),
].join('\n');
