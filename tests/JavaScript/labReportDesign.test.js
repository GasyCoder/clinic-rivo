import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    LAB_REPORT_CHOICES, LAB_REPORT_COLOR_FIELDS, LAB_REPORT_FIELDS, LAB_REPORT_NUMBERS, LAB_REPORT_SIGNATORIES,
    LAB_REPORT_SWITCHES, LAB_REPORT_TEMPLATES, LAB_REPORT_TEXT_LIMITS, labReportFormValue, labReportPreviewQuery,
} from '../../resources/js/utilities/labReportDesign.js';
import { settingsSection } from '../../resources/js/utilities/settingsSections.js';

/** ADR-223 — les réglages du compte rendu d'analyses : la même liste des deux côtés. */
const read = (path) => fs.readFileSync(path, 'utf8');

test('the report settings are the same list on both sides, with the same defaults', () => {
    const server = read('app/Support/Laboratory/LabReportDesign.php');
    const list = (name) => server.match(new RegExp(`public const ${name} = \\[([\\s\\S]*?)\\n    \\];`))[1];
    const quoted = (text) => [...text.matchAll(/'([A-Za-z0-9_]+)'/g)].map((match) => match[1]);

    assert.deepEqual(quoted(list('FIELDS')), [...LAB_REPORT_FIELDS]);
    assert.deepEqual(quoted(server.match(/public const COLORS = \[([^\]]*)\]/)[1]), [...LAB_REPORT_COLOR_FIELDS]);

    for (const [field, max] of Object.entries(LAB_REPORT_TEXT_LIMITS)) {
        assert.ok(list('TEXTS').includes(`'${field}' => ${max}`), `${field} : même longueur`);
    }
    for (const [field, values] of Object.entries(LAB_REPORT_CHOICES)) {
        const line = list('CHOICES').match(new RegExp(`'${field}' => \\[([^\\]]*)\\]`))[1];
        assert.deepEqual(quoted(line), values, `${field} : mêmes choix, valeur d’origine en premier`);
    }
    for (const [field, bounds] of Object.entries(LAB_REPORT_NUMBERS)) {
        assert.ok(list('NUMBERS').includes(`'${field}' => [${bounds.join(', ')}]`), `${field} : mêmes bornes`);
    }
    for (const [field, value] of Object.entries(LAB_REPORT_SWITCHES)) {
        assert.ok(list('SWITCHES').includes(`'${field}' => ${value}`), `${field} : même valeur par défaut`);
    }

    // Chaque réglage a sa colonne, et le logo propre au compte rendu aussi.
    const migration = read('database/migrations/2026_11_25_090000_add_lab_report_design_to_app_settings.php');
    for (const field of [...LAB_REPORT_FIELDS, 'lab_report_logo_path']) {
        assert.ok(migration.includes(`'${field}'`), `la migration crée ${field}`);
    }
    // Tout ce qui se choisit a son libellé à l'écran.
    assert.deepEqual(Object.keys(LAB_REPORT_TEMPLATES), LAB_REPORT_CHOICES.lab_report_template);
    assert.deepEqual(Object.keys(LAB_REPORT_SIGNATORIES), LAB_REPORT_CHOICES.lab_report_signatory);
});

test('a setting never set shows the original report', () => {
    assert.equal(labReportFormValue('lab_report_template', null), 'CLASSIC');
    assert.equal(labReportFormValue('lab_report_template', 'FANCY'), 'CLASSIC');
    assert.equal(labReportFormValue('lab_report_signatory', 'AUTO'), 'AUTO');
    assert.equal(labReportFormValue('lab_report_font_size', ''), 100);
    assert.equal(labReportFormValue('lab_report_font_size', 300), 120);
    assert.equal(labReportFormValue('lab_report_show_qr', null), false);
    assert.equal(labReportFormValue('lab_report_show_sent', null), true);
    assert.equal(labReportFormValue('lab_report_show_sent', 0), false);
    assert.equal(labReportFormValue('lab_report_accent_color', '#0f766e'), '#0F766E');
    assert.equal(labReportFormValue('lab_report_website', null), '');
});

test('the preview sends switches as 1 / 0 and leaves empty texts out', () => {
    const query = labReportPreviewQuery({ lab_report_show_qr: true, lab_report_zebra: false, lab_report_website: '  ', lab_report_heading: ' Labo ', lab_report_template: 'BANNER' });

    assert.equal(query.lab_report_show_qr, '1');
    assert.equal(query.lab_report_zebra, '0');
    assert.equal(query.lab_report_show_sent, '1', 'un interrupteur jamais réglé part à sa valeur d’origine');
    assert.equal(query.lab_report_heading, 'Labo');
    assert.equal(query.lab_report_template, 'BANNER');
    assert.ok(! ('lab_report_website' in query));
});

test('the settings module is a site-only page with a live preview', () => {
    const section = settingsSection('compte-rendu');
    assert.equal(section.group, 'etablissement');
    assert.deepEqual(section.fields, [...LAB_REPORT_FIELDS]);

    const component = read('resources/js/Components/Settings/LabReportSettings.vue');
    assert.match(component, /\/super-admin\/settings\/lab-report-preview\?/);
    assert.match(component, /site_code: props\.siteCode/, 'le portail attend site_code');
    assert.match(component, /URL\.revokeObjectURL/, 'un aperçu remplacé libère sa mémoire');
    assert.match(component, /v-if="isPortal"/, 'le portail n’imprime aucun compte rendu');
    assert.doesNotMatch(component, /Components\/UI\//);

    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');
    assert.match(page, /\.\.\.LAB_REPORT_FIELDS/);
    assert.match(page, /labReportFormValue\(field, value\)/);

    // Un aperçu est une lecture : GET, jamais une clé d'idempotence.
    assert.match(read('routes/api.php'), /Route::get\('\/app-settings\/lab-report-preview'/);
    assert.match(read('routes/web.php'), /Route::get\('\/settings\/lab-report-preview'.*can:settings\.view/);
});
