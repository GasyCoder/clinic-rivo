import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    MAINTENANCE_DURATIONS, MAINTENANCE_STATES, addMinutes, msUntil, nextMinuteInput, returnLabel, toLocalInput, whenLabel, windowLabel,
} from '../../resources/js/utilities/maintenance.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

// Toutes les dates sont construites à l'heure du poste : les phrases le sont aussi.
const at = (day, hour, minute = 0) => new Date(2026, 8, day, hour, minute);
const NOW = at(25, 14, 7);

test('a date-and-time field is written at the machine time, to the minute', () => {
    assert.equal(toLocalInput(at(25, 9, 5)), '2026-09-25T09:05');
    assert.equal(toLocalInput(''), '');
    assert.equal(toLocalInput('pas une date'), '');
    assert.equal(addMinutes('2026-09-25T22:00', 90), '2026-09-25T23:30');
    assert.equal(addMinutes('2026-09-25T23:30', 60), '2026-09-26T00:30', 'le jour suivant');
    assert.equal(addMinutes('', 30), '');
    assert.equal(nextMinuteInput(NOW), '2026-09-25T14:08', 'un début programmé est à venir : la minute suivante');
});

test('the window reads as one sentence', () => {
    assert.equal(windowLabel(at(25, 22), at(25, 23, 30), NOW), 'aujourd’hui de 22:00 à 23:30');
    assert.equal(windowLabel(at(26, 6), null, NOW), 'à partir de demain à 06:00');
    assert.match(windowLabel(at(28, 22), at(29, 2), NOW), /^lundi 28 septembre à 22:00 jusqu’à mardi 29 septembre à 02:00$/);
    assert.equal(whenLabel(at(25, 22), NOW), 'aujourd’hui à 22:00');
    assert.equal(whenLabel(at(26, 6), NOW), 'demain à 06:00');
});

test('the return time is said, or that the site reopens when the work is done', () => {
    assert.equal(returnLabel(at(25, 23, 30), NOW), 'Retour prévu aujourd’hui à 23:30.');
    assert.equal(returnLabel(null, NOW), 'Retour dès la fin de l’intervention.');
    assert.equal(msUntil(at(25, 14, 8), NOW), 60 * 1000);
    assert.equal(msUntil(at(25, 14), NOW), 0, 'une heure passée ne donne pas d’attente négative');
});

test('every server state has a label and quick durations are offered', () => {
    assert.deepEqual(Object.keys(MAINTENANCE_STATES).sort(), ['ACTIVE', 'ENDED', 'LIFTED', 'UPCOMING']);
    assert.ok(MAINTENANCE_DURATIONS.every((duration) => duration.minutes > 0 && duration.label));
});

test('the site page and the portal preview show the same notice, as plain text', () => {
    const notice = read('resources/js/Components/Maintenance/MaintenanceNotice.vue');
    const page = read('resources/js/Pages/Maintenance.vue');
    const settings = read('resources/js/Components/Settings/MaintenanceSettings.vue');

    assert.doesNotMatch(notice, /v-html/, 'le message n’est jamais interprété comme du HTML');
    assert.match(notice, /whitespace-pre-line/, 'ses retours à la ligne sont gardés');
    assert.match(page, /<MaintenanceNotice /);
    assert.match(settings, /<MaintenanceNotice [^>]*compact/, 'l’aperçu du portail est le composant même de la page');
    assert.match(page, /router\.post\('\/logout'\)/, 'un compte connecté sans le droit peut se déconnecter');
    assert.match(page, /href="\/login"/, 'la connexion reste ouverte au compte qui doit vérifier le site');
});

test('the portal module acts on its own and never nests a form', () => {
    const settings = read('resources/js/Components/Settings/MaintenanceSettings.vue');
    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');

    assert.match(settings, /v-else-if="maintenance\.available !== true"/, 'un site qui ne répond rien de lisible n’est jamais présenté comme ouvert');
    assert.doesNotMatch(settings, /<form\b/, 'il vit dans le formulaire des paramètres : un <form> imbriqué serait invalide');
    assert.match(settings, /can\('app_maintenance\.update'\)/, 'ses propres droits, pas settings.update');
    assert.match(settings, /\.put\('\/super-admin\/settings\/maintenance'/);
    assert.match(settings, /\.post\('\/super-admin\/settings\/maintenance\/lift'/);
    assert.match(page, /usesCommonForm = computed\(\(\) => current\.value\.fields\.length > 0\)/);
    assert.match(page, /v-if="! readonly && usesCommonForm" class="sticky bottom-0/, 'pas de « Enregistrer » commun sous la maintenance');
});

test('the layout warns before and reminds whoever works through it', () => {
    const layout = read('resources/js/Layouts/AppLayout.vue');
    const banner = read('resources/js/Components/Layout/MaintenanceBanner.vue');
    const login = read('resources/js/Pages/Auth/Login.vue');

    assert.match(layout, /<MaintenanceBanner \/>/);
    assert.match(banner, /page\.props\.site\?\.maintenance/, 'servi par le serveur, jamais calculé');
    assert.match(banner, /state === 'UPCOMING'/);
    assert.match(banner, /state === 'ACTIVE' && maintenance\.value\.bypassing/);
    assert.match(login, /site\.value\.maintenance\?\.state === 'ACTIVE'/);
});
