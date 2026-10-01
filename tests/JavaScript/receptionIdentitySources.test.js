import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { PARTNERS_SITE_BASE, partnersPath } from '../../resources/js/utilities/partnerUrl.js';
import { useReceptionLookup } from '../../resources/js/composables/useReceptionLookup.js';

/**
 * ADR-211 — l'accueil retrouve la personne dans le dossier du personnel ou dans
 * les fiches partenaires au lieu de ressaisir son identité ; le module
 * Partenaires vit sur le site et s'ouvre depuis le portail par l'API du site.
 */
const read = (file) => fs.readFileSync(file, 'utf8');
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);

    return entry.isDirectory() ? walk(full) : (entry.name.endsWith('.vue') ? [full] : []);
});

const reception = read('resources/js/Pages/Reception/Create.vue');
const staffPicker = read('resources/js/Components/Reception/StaffIdentityPicker.vue');

test('une adresse du module Partenaires est ramenée à la base où il est ouvert', () => {
    const base = '/super-admin/sites/A/partenaires';

    assert.equal(partnersPath('', base), base);
    assert.equal(partnersPath('/p-1/restore', base), `${base}/p-1/restore`);
    assert.equal(partnersPath('p-1', base), `${base}/p-1`);
    assert.equal(partnersPath('/p-1'), `${PARTNERS_SITE_BASE}/p-1`, 'sur le site, la base est /partenaires');
});

test('aucun écran du module n’écrit une adresse que le portail ne saurait suivre', () => {
    for (const file of [...walk('resources/js/Pages/Partners'), ...walk('resources/js/Components/Partners')]) {
        const source = read(file);
        const bare = source.match(/(?<!partnerUrl\()['"`]\/partenaires(?=[/?#'"`])[^'"`]*['"`]/g) ?? [];

        assert.deepEqual(bare, [], `${file} écrit une adresse des Partenaires sans partnerUrl()`);
    }
});

/** Une réponse que l'on contrôle, qui se rejette comme fetch quand la recherche est annulée. */
const deferredFetch = () => {
    const calls = [];
    const fetch = (url, { signal }) => new Promise((resolve, reject) => {
        const call = {
            url,
            respond: (data) => resolve({ ok: true, json: async () => ({ data }) }),
        };
        signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        calls.push(call);
    });

    return { calls, fetch };
};
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('une recherche plus récente n’est jamais écrasée par la précédente, ni déclarée finie à sa place', async () => {
    const { calls, fetch } = deferredFetch();
    const original = globalThis.fetch;
    const warn = console.warn;
    globalThis.fetch = fetch;
    console.warn = () => {};

    try {
        const lookup = useReceptionLookup('/reception/employees/patient-lookup', { delay: 10_000 });

        lookup.query.value = 'Rak';
        const first = lookup.search();
        lookup.query.value = 'Rakoto';
        const second = lookup.search();
        await first;

        assert.equal(lookup.loading.value, true, 'la recherche annulée ne remet pas « terminé »');
        assert.match(calls[1].url, /q=Rakoto$/);

        calls[1].respond([{ uuid: 'p-1' }]);
        await second;
        await flush();

        assert.equal(lookup.loading.value, false);
        assert.deepEqual(lookup.results.value.map((row) => row.uuid), ['p-1']);
        assert.equal(lookup.error.value, '');
    } finally {
        globalThis.fetch = original;
        console.warn = warn;
    }
});

test('l’étape Patient offre trois façons de retrouver la personne ; un partenaire se choisit à la prise en charge', () => {
    assert.match(reception, /\{ value: 'search', label: 'Patient existant'/);
    assert.match(reception, /props\.capabilities\.can_use_staff && props\.capabilities\.can_link_staff\s*&& \{ value: 'staff', label: 'Personnel & stagiaires'/);
    assert.match(reception, /props\.capabilities\.can_create_patient && \{ value: 'create', label: 'Nouveau patient'/);
    assert.doesNotMatch(reception, /value: 'partner'|PartnerIdentityPicker|partner_uuid/, 'plus d’onglet « Partenaire médical » (ADR-211, amendement du 2026-09-28)');
    assert.match(reception, /\{ mode: 'PARTNER', label: 'Partenaire', icon: Handshake/, 'le partenaire reste choisi à l’étape 5');
});

test('un dossier similaire se relie à la fiche au lieu d’être doublé', () => {
    assert.match(reception, /C’est la même personne/);
    assert.match(reception, /patient_uuid: linkTarget\.value\.uuid, employee_uuid: employee\.uuid/);
});

test('la prise en charge est proposée d’après la fiche reliée, jamais Personnel pour un stagiaire', () => {
    const proposal = reception.slice(reception.indexOf('const proposedFinancialMode'), reception.indexOf('const financialMode = ref'));

    assert.match(proposal, /employee\?\.staff_coverage_eligible/, 'un stagiaire n’a jamais la prise en charge Personnel proposée');
    assert.match(proposal, /partner\?\.active/);
    assert.match(reception, /<Badge v-if="proposedFinancialMode === card\.mode" variant="secondary">Proposé/);
    assert.match(reception, /const staffCoverageEmployee = computed\(\(\) => \(patientLinks\.value\.employee\?\.staff_coverage_eligible/, 'un stagiaire ne se choisit pas au mode Personnel');
    assert.match(reception, /if \(patientLinks\.value\.employee\?\.is_intern\) return 'Stagiaire : tarif Standard/);
});

test('ADR-212 — l’étape 5 ne cherche plus d’employé : seul le dossier RH relié ouvre la prise en charge Personnel', () => {
    assert.doesNotMatch(reception, /searchEmployees|employeeQuery|employeeMatches/, 'plus de recherche d’employé à la prise en charge');
    assert.match(reception, /\{ mode: 'STAFF', label: 'Personnel', icon: Briefcase, disabled: ! staffCardAvailable\.value/);
    assert.match(reception, /if \(mode === 'STAFF' && ! staffCardAvailable\.value\) return;/);
});

test('une fiche RH sans date de naissance n’ouvre pas de dossier, et le dit', () => {
    assert.match(staffPicker, /if \(! employee\.can_open_patient_record\) return 'Date de naissance absente du dossier RH'/);
    assert.match(staffPicker, /if \(blockedReason\(employee\)\) return;/);
});

test('une fenêtre ne dépasse jamais l’écran : en-tête et pied restent, le contenu défile', () => {
    const dialog = read('resources/js/Components/Shadcn/Dialog.vue');

    assert.match(dialog, /flex max-h-\[calc\(100dvh-2rem\)\][^']*flex-col overflow-hidden/);
    assert.match(dialog, /<header class="flex shrink-0 /);
    assert.match(dialog, /cn\('min-h-0 flex-1 overflow-y-auto px-6 py-5', bodyClass\)/);
    assert.match(dialog, /<footer v-if="\$slots\.footer" class="flex shrink-0 /);
});

test('la fiche partenaire se remplit sur sa propre page, en deux colonnes, adresse du référentiel', () => {
    const index = read('resources/js/Pages/Partners/Index.vue');
    const page = read('resources/js/Pages/Partners/Form.vue');

    assert.doesNotMatch(index, /id="partner-form"/, 'plus de fenêtre de création dans la liste');
    assert.match(index, /const createUrl = partnerUrl\('\/nouveau'\);/);
    assert.match(index, /partnerUrl\(`\/\$\{partner\.uuid\}\/modifier`\)/);
    assert.match(page, /xl:grid-cols-2/);
    assert.match(page, /<AddressEntryField\s+v-if="canPickAddress"/);
    assert.doesNotMatch(page, /v-model="form\.address"/, 'plus de texte libre à côté du référentiel');
    assert.match(page, /const canPickAddress = computed\(\(\) => can\('address_entries\.view'\)\)/);
});

test('un seul champ d’adresse pour les fiches : le partenaire et l’employé', () => {
    const field = read('resources/js/Components/Administration/AddressEntryField.vue');
    // ADR-221 — l'adresse d'un employé se règle à l'étape Contact de son dossier.
    const employee = read('resources/js/Components/Administration/EmployeeFile/ContactSection.vue');

    assert.match(employee, /<AddressEntryField[\s\S]*?:entry="form\.address_entry_uuid"/);
    assert.doesNotMatch(employee, /id="new_address_label"/, 'la fiche employé ne recopie plus le champ');
    assert.match(field, /entryId: \{ type: String, default: 'address_entry_uuid' \}/, 'le résumé d’erreurs mène toujours au champ');
    assert.match(field, /newLabelId: \{ type: String, default: 'new_address_label' \}/);
    assert.match(field, /if \(mode === 'new'\) emit\('update:entry', ''\);\s*else emit\('update:newLabel', ''\);/, 'les deux champs ne partent jamais ensemble');
});
