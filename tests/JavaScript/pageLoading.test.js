import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { skeletonFor } from '../../resources/js/utilities/pageSkeleton.js';
import { isPageVisit } from '../../resources/js/composables/usePageLoading.js';

const read = (path) => fs.readFileSync(path, 'utf8');

/** Le squelette prend la forme de la page qui arrive, lue sur son adresse. */
test('the skeleton follows the kind of page being opened', () => {
    assert.equal(skeletonFor('/'), 'dashboard');
    assert.equal(skeletonFor('/super-admin'), 'dashboard');
    assert.equal(skeletonFor('/patients'), 'list');
    assert.equal(skeletonFor('/pharmacy/stock?filter=low'), 'list');
    assert.equal(skeletonFor('/patients/0b6f8c52-2c1e-4f5b-9a4e-1d2c3b4a5f60'), 'detail');
    assert.equal(skeletonFor('/medicine/orientations/0b6f8c52-2c1e-4f5b-9a4e-1d2c3b4a5f60/examen'), 'detail');
    assert.equal(skeletonFor('/pharmacy-suppliers/A/12'), 'detail');
    assert.equal(skeletonFor('/pharmacy/supplier-invoices/create'), 'form');
    assert.equal(skeletonFor('/employees/4/edit'), 'form');
    assert.equal(skeletonFor('/reception/passages/0b6f8c52-2c1e-4f5b-9a4e-1d2c3b4a5f60/prise-en-charge'), 'form');
    assert.equal(skeletonFor('/passages/0b6f8c52-2c1e-4f5b-9a4e-1d2c3b4a5f60/dossier-medical'), 'document');
    assert.equal(skeletonFor('/contracts/9/print'), 'document');
    assert.equal(skeletonFor('/hospitalisation/selection/tour-de-salle'), 'document');
    assert.equal(skeletonFor('/super-admin/settings'), 'settings');
    assert.equal(skeletonFor('/profil'), 'settings');
    assert.equal(skeletonFor(''), 'dashboard');
});

/** Seul un vrai changement de page est remplacé par un squelette. */
test('only a real page change shows the skeleton', () => {
    const visit = { method: 'get', preserveState: false, only: [], prefetch: false, async: false };

    assert.ok(isPageVisit(visit));
    assert.ok(! isPageVisit({ ...visit, method: 'post' }), 'un envoi de formulaire garde sa page');
    assert.ok(! isPageVisit({ ...visit, preserveState: true }), 'une recherche au fil de la frappe garde sa page');
    assert.ok(! isPageVisit({ ...visit, preserveState: 'errors' }));
    assert.ok(! isPageVisit({ ...visit, only: ['patients'] }), 'un rechargement partiel garde sa page');
    assert.ok(! isPageVisit({ ...visit, prefetch: true }));
    assert.ok(! isPageVisit({ ...visit, async: true }));
    assert.ok(! isPageVisit(null));
});

test('the layout keeps the page mounted behind the skeleton', () => {
    const layout = read('resources/js/Layouts/AppLayout.vue');
    const app = read('resources/js/app.js');
    const skeleton = read('resources/js/Components/Layout/PageSkeleton.vue');

    assert.match(layout, /<PageSkeleton v-if="pageLoading\.active" :path="pageLoading\.path" \/>/);
    // ADR-187 — la barre RH du portail précède la page, dans le même bloc masqué ;
    // ADR-193 — le bandeau de maintenance aussi.
    assert.match(layout, /<div v-show="! pageLoading\.active">\s*(?:<HrPortalBar v-if="page\.props\.hrContext" \/>\s*)?(?:<PharmacyPortalBar v-if="page\.props\.pharmacyContext" \/>\s*)?(?:<MaintenanceBanner \/>\s*)?<slot \/>/, 'cachée, jamais démontée : une visite annulée rend la page intacte');
    assert.match(app, /installPageLoading\(router\)/);
    assert.match(skeleton, /role="status"/);
    assert.match(skeleton, /Chargement de la page…/);
    assert.match(read('resources/js/Components/Shadcn/Skeleton.vue'), /animate-pulse rounded-md bg-muted/);
});

/** Clair, Système ou Sombre — et « Système » suit l'appareil. */
test('the appearance offers light, system and dark', () => {
    const store = read('resources/js/stores/theme.js');
    const sync = read('resources/js/composables/useThemeSync.js');
    const blade = read('resources/views/app.blade.php');
    const switcher = read('resources/js/Components/Layout/ThemeModeSwitcher.vue');

    assert.match(store, /export const THEME_MODES = \['light', 'system', 'dark'\]/);
    assert.match(store, /usePreferredDark\(\)/);
    assert.match(store, /initOnMounted: true/, 'jamais lu pendant le rendu serveur');
    assert.match(sync, /theme\.resolved === 'dark'/);
    assert.match(blade, /mode === 'system' && window\.matchMedia\('\(prefers-color-scheme: dark\)'\)\.matches/, 'posé avant le premier affichage');
    for (const label of ['Clair', 'Système', 'Sombre']) {
        assert.match(switcher, new RegExp(`label: '${label}'`));
    }
    assert.match(switcher, /role="radiogroup"/);
    const header = read('resources/js/Components/Layout/Header.vue');
    assert.match(header, /<ThemeModeSwitcher variant="header" class="hidden sm:inline-flex" \/>\s*<span class="hidden h-6 w-px shrink-0 bg-border sm:block" aria-hidden="true" \/>\s*<HeaderAttention \/>/, 'dans la barre, séparé de la cloche par un trait');
    assert.match(header, /<li class="px-7 py-2\.5 sm:hidden">/, 'le menu du compte ne le garde que sur téléphone');
    assert.match(header, /<ThemeModeSwitcher variant="menu" \/>/);
    assert.match(read('resources/js/Layouts/GuestLayout.vue'), /<ThemeModeSwitcher variant="floating"/);
    assert.doesNotMatch(read('resources/js/Components/Layout/Header.vue'), /updateMode/);
});
