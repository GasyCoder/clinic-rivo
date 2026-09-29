import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { LAB_SITE_BASE, mapLabPath } from '../../resources/js/utilities/labPath.js';
import { activeLabSection, labSections } from '../../resources/js/utilities/labSections.js';

/**
 * ADR-215 — les écrans du Laboratoire du site sont aussi ceux du portail. Ils
 * écrivent leurs adresses telles qu'elles sont sur le site ; `labUrl` les
 * ramène à la base où l'écran est ouvert. Les gestes cliniques restent au site.
 */
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    return entry.isDirectory() ? walk(full) : (entry.name.endsWith('.vue') ? [full] : []);
});

// La barre de navigation du portail écrit déjà des adresses du portail.
const PORTAL_ONLY_FILES = ['LaboratoryPortalBar.vue'];
const labScreens = () => [...walk('resources/js/Pages/Laboratory'), ...walk('resources/js/Components/Laboratory')]
    .filter((file) => ! PORTAL_ONLY_FILES.includes(path.basename(file)));

const read = (file) => fs.readFileSync(file, 'utf8');

test('a site path is brought to the base where the laboratory screen is open', () => {
    const base = '/super-admin/sites/A/laboratoire';

    assert.equal(mapLabPath('/laboratory/requests/r-1', base), `${base}/requests/r-1`);
    assert.equal(mapLabPath('/laboratory', base), base);
    assert.equal(mapLabPath('/laboratory?view=to_do', base), `${base}?view=to_do`);
    assert.equal(mapLabPath('/laboratory/rapports', LAB_SITE_BASE), '/laboratory/rapports', 'sur le site, rien ne change');
    assert.equal(mapLabPath('/laboratory-archives', base), '/laboratory-archives', 'un préfixe n’est pas une rubrique');
    assert.equal(mapLabPath('/patients/p-1', base), '/patients/p-1');
});

test('no laboratory screen writes a site path the portal could not follow', () => {
    for (const file of labScreens()) {
        const source = read(file);
        const bare = source.match(/(?<!labUrl\()['"`]\/laboratory(?=[/?#'"`])[^'"`]*['"`]/g) ?? [];

        assert.deepEqual(bare, [], `${file} écrit une adresse du Laboratoire sans labUrl()`);

        if (source.includes('labUrl(')) {
            assert.match(source, /import \{[^}]*\blabUrl\b[^}]*\} from '@\/utilities\/labUrl'/, `${file} utilise labUrl sans l’importer`);
        }
    }
});

test('the clinical gestures are shown locked on the portal, never hidden', () => {
    const locks = {
        'resources/js/Components/Laboratory/LabReceptionPanel.vue': ['Commencer le traitement'],
        'resources/js/Components/Laboratory/LabSamplesCard.vue': ['Ajouter'],
    };

    for (const [file, labels] of Object.entries(locks)) {
        const source = read(file);

        assert.match(source, /import LabSiteOnlyAction from '@\/Components\/Laboratory\/LabSiteOnlyAction\.vue'/, `${file} n’importe pas LabSiteOnlyAction`);
        for (const label of labels) {
            assert.match(source, new RegExp(`<LabSiteOnlyAction [^>]*label="${label}"`), `${file} : « ${label} » n’est pas verrouillé sur le portail`);
        }
    }

    const show = read('resources/js/Pages/Laboratory/Show.vue');
    assert.match(show, /<LabSiteOnlyAction v-if="[^"]*" label="Envoyer au médecin"/, 'envoyer au médecin est verrouillé');

    const editor = read('resources/js/Components/Laboratory/LabItemEditor.vue');
    assert.match(editor, /can\.site_only/, 'la saisie dit pourquoi elle est en lecture seule sur le portail');
    assert.match(editor, /label: 'Terminer l’analyse'/);

    const lock = read('resources/js/Components/Laboratory/LabSiteOnlyAction.vue');
    assert.match(lock, /<slot v-if="! onPortal" \/>/, 'sur le site, le bouton est rendu tel quel');
    assert.match(lock, /disabled/);
    assert.match(lock, /LAB_SITE_ONLY_REASON/, 'le verrou dit pourquoi');
});

test('the site refuses the clinical gestures to the portal, whatever the screen says', () => {
    const routes = read('routes/laboratory.php');

    for (const name of [
        'requests.send', 'items.results', 'items.antibiograms.update', 'items.return',
        'results.critical', 'items.result', 'requests.receive', 'requests.samples',
        'requests.conclusion', 'samples.reject', 'items.send-out', 'items.send-out.cancel', 'items.reset',
    ]) {
        assert.match(routes, new RegExp(`->name\\('${name.replaceAll('.', '\\.')}'\\)[^;]*rivo\\.site-only:laboratory`), `${name} doit rester au site`);
    }

    // Lire et gérer les référentiels restent ouverts au portail.
    for (const name of ['index', 'requests.show', 'reports', 'microbiology.store', 'sample-types.store']) {
        assert.doesNotMatch(routes, new RegExp(`->name\\('${name.replaceAll('.', '\\.')}'\\)[^;]*rivo\\.site-only`), `${name} se consulte ou se gère depuis le portail`);
    }
});

test('the portal shows the laboratory navigation of the site, from the one list of laboratory sections', () => {
    const base = '/super-admin/sites/A/laboratoire';
    const everything = labSections(base, () => true);

    assert.deepEqual(everything.map((section) => section.label), ['Paillasse', 'Feuille de paillasse', 'Rapports du laboratoire', 'Prélèvements & tubes', 'Germes & antibiotiques']);
    assert.equal(everything[0].href, base);
    assert.equal(everything[2].href, `${base}/rapports`);

    const reader = labSections(base, (permission) => permission === 'laboratory_results.view');
    assert.deepEqual(reader.map((section) => section.code), ['laboratory', 'lab-worklist'], 'chaque rubrique garde son droit');

    assert.equal(activeLabSection(everything, `${base}/requests/r-1`), 'laboratory', 'une demande relève de la Paillasse');
    assert.equal(activeLabSection(everything, `${base}/paillasse`), 'lab-worklist', 'la rubrique la plus précise l’emporte');
    assert.equal(activeLabSection(everything, '/ailleurs'), null);

    const layout = read('resources/js/Layouts/AppLayout.vue');
    const bar = read('resources/js/Components/Laboratory/LaboratoryPortalBar.vue');

    assert.match(layout, /<LaboratoryPortalBar v-if="page\.props\.laboratoryContext" \/>/);
    assert.match(bar, /labSections\(base\.value, can\)/);
    assert.match(bar, /print:hidden/);

    const menu = read('resources/js/Components/Layout/Menu.vue');
    assert.match(menu, /link: '\/super-admin\/laboratory', permission: 'laboratory_results\.view'/, 'le portail a son entrée « Laboratoire des sites »');
});
