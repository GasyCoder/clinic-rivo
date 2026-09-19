import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-099 : shadcn-vue est le design system, DashWind un reliquat.
 *
 * Le dossier d'un patient, la file Soins et les journaux de traitement sont
 * des écrans retouchés : ils s'écrivent avec la couche `Components/Shadcn`,
 * les tokens sémantiques et `lucide-vue-next` — jamais la police d'icônes ni
 * la palette DashWind.
 */
const files = [
    'resources/js/Pages/Patients/Index.vue',
    'resources/js/Pages/Patients/Show.vue',
    'resources/js/Components/Clinical/PatientNeedBadges.vue',
    'resources/js/Pages/Care/Index.vue',
    'resources/js/Pages/Medicine/PatientTreatmentJournals.vue',
    'resources/js/Pages/Medicine/TreatmentJournalSheet.vue',
    'resources/js/Components/Clinical/TreatmentJournalTable.vue',
    'resources/js/Components/Clinical/PaperSheet.vue',
];

const read = (file) => fs.readFileSync(file, 'utf8');
const label = (file) => file.replace('resources/js/', '');

test('les écrans retouchés n’importent plus aucun composant DashWind', () => {
    for (const file of files) {
        const source = read(file);

        for (const dashwind of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue', 'UI/IconInput.vue', 'UI/Card.vue', 'UI/FormGroup.vue', 'UI/FormLabel.vue']) {
            assert.ok(!source.includes(dashwind), `${label(file)} importe encore ${dashwind}`);
        }

        assert.doesNotMatch(source, /<Icon[ />]/, `${label(file)} rend encore la police d’icônes`);
        assert.doesNotMatch(source, /<Form(Group|Label|Error)[ />]/, `${label(file)} utilise encore un champ DashWind`);
    }
});

/**
 * Une couleur codée en dur ne suit pas le thème. `TreatmentJournalTable` et
 * `PaperSheet` écrivent volontairement leurs couleurs en CSS : elles décrivent
 * du papier imprimé, pas une interface — ce que cette règle ne cherche pas.
 */
test('les écrans retouchés utilisent les tokens sémantiques', () => {
    for (const file of files) {
        const source = read(file);

        for (const legacy of [/text-slate-[0-9]/, /border-gray-[0-9]/, /bg-gray-[0-9]/, /\bbg-white\b/, /-primary-[0-9]/, /dark:bg-gray/, /gray-1000/]) {
            assert.doesNotMatch(source, legacy, `${label(file)} porte encore ${legacy}`);
        }
    }
});

test('le dossier patient n’emploie plus les classes de la police d’icônes', () => {
    const source = read('resources/js/Pages/Patients/Show.vue');

    assert.doesNotMatch(source, /\bnk-/);
    assert.doesNotMatch(source, /\bni ni-/);
});

test('les statuts du dossier patient se disent par une pastille et une icône lucide', () => {
    const source = read('resources/js/Pages/Patients/Show.vue');

    // Plus de tables de classes recopiées : le thème décide de la couleur.
    assert.doesNotMatch(source, /StatusBadgeClass|StatusIconClass|StatusIcon\b/);
    assert.match(source, /const PATHWAY_BADGES = \{/);
    assert.match(source, /const EPISODE_STATUSES = \{/);
    assert.match(source, /const INVOICE_VARIANTS = \{/);
    assert.match(source, /from 'lucide-vue-next'/);
});

test('les champs du dossier patient passent par FormField', () => {
    const source = read('resources/js/Pages/Patients/Show.vue');

    assert.match(source, /import FormField from '@\/Components\/Shadcn\/FormField\.vue'/);
    assert.match(source, /<FormField label="Montant" required/);
    assert.match(source, /<FormField label="Motif" required/);
});
