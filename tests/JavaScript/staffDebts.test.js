import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { debtPlan, debtSteps, interestFor, isOpenDebt, quickMonths, repaidShare, ruleIssues, tierLabel, totalWithInterest } from '../../resources/js/utilities/staffDebts.js';
import { mapStaffDebtPath } from '../../resources/js/utilities/staffDebtPath.js';

/*
 * ADR-228 — « Mes dettes » : l'avancement d'une dette en quatre étapes, lu sur l'état et
 * l'historique servis par le serveur, et la page en pleine largeur.
 */
const read = (path) => fs.readFileSync(`resources/js/${path}`, 'utf8');
const MINE = 'Pages/StaffDebts/Mine.vue';

const debt = (overrides = {}) => ({
    status: 'REQUESTED',
    requested_at: '2026-09-10T08:00:00+03:00',
    granted: null,
    disbursement: null,
    amount: null,
    repaid: '0.00',
    written_off_amount: '0.00',
    timeline: [{ key: 'requested', at: '2026-09-10T08:00:00+03:00', by: 'Vola' }],
    ...overrides,
});
const states = (item) => debtSteps(item).map((step) => `${step.key}:${step.state}`);

test('une demande attend la décision du DG ; versement et remboursement sont à venir', () => {
    assert.deepEqual(states(debt()), ['request:done', 'decision:current', 'disbursement:upcoming', 'repayment:upcoming']);
});

test('une demande retirée s’arrête à la décision, sans versement ni remboursement', () => {
    const withdrawn = debt({ status: 'CANCELLED', timeline: [...debt().timeline, { key: 'cancelled', at: '2026-09-11T09:00:00+03:00', by: 'Vola' }] });
    assert.deepEqual(states(withdrawn), ['request:done', 'decision:stopped', 'disbursement:skipped', 'repayment:skipped']);
    assert.equal(debtSteps(withdrawn)[1].label, 'Demande retirée');
});

test('un refus s’arrête à la décision', () => {
    const refused = debt({ status: 'REFUSED', timeline: [...debt().timeline, { key: 'refused', at: '2026-09-12T09:00:00+03:00', by: 'DG' }] });
    assert.deepEqual(states(refused), ['request:done', 'decision:stopped', 'disbursement:skipped', 'repayment:skipped']);
    assert.equal(debtSteps(refused)[1].at, '2026-09-12T09:00:00+03:00');
});

test('accordée puis annulée avant versement : l’arrêt est au versement', () => {
    const cancelled = debt({ status: 'CANCELLED', granted: { adjusted: false }, amount: '300000.00' });
    assert.deepEqual(states(cancelled), ['request:done', 'decision:done', 'disbursement:stopped', 'repayment:skipped']);
});

test('accordée, ajustée, attend son versement', () => {
    const approved = debt({ status: 'APPROVED', granted: { adjusted: true }, amount: '300000.00' });
    assert.deepEqual(states(approved), ['request:done', 'decision:done', 'disbursement:current', 'repayment:upcoming']);
    assert.equal(debtSteps(approved)[1].label, 'Accordée · ajustée');
});

test('en remboursement : la part remboursée se lit sur l’étape en cours', () => {
    const active = debt({ status: 'ACTIVE', granted: { adjusted: false }, amount: '300000.00', repaid: '100000.00', disbursement: { on: '2026-09-20' } });
    assert.deepEqual(states(active), ['request:done', 'decision:done', 'disbursement:done', 'repayment:current']);
    assert.equal(debtSteps(active)[3].note, '33 % remboursé');
    assert.equal(debtSteps(active)[2].at, '2026-09-20');
});

test('soldée ou reste remis : tout est fait', () => {
    for (const status of ['SETTLED', 'WRITTEN_OFF']) {
        const closed = debt({ status, granted: { adjusted: false }, amount: '300000.00', disbursement: { on: '2026-09-20' } });
        assert.deepEqual(states(closed), ['request:done', 'decision:done', 'disbursement:done', 'repayment:done']);
    }
});

test('la part remboursée compte le reste remis et ne dépasse jamais 100 %', () => {
    assert.equal(repaidShare({ amount: '300000.00', repaid: '100000.00', written_off_amount: '200000.00' }), 100);
    assert.equal(repaidShare({ amount: '300000.00', repaid: '400000.00', written_off_amount: '0.00' }), 100);
    assert.equal(repaidShare({ amount: null, repaid: '0.00' }), 0);
});

test('en cours ou close', () => {
    assert.deepEqual(['REQUESTED', 'APPROVED', 'ACTIVE', 'SETTLED', 'REFUSED', 'CANCELLED', 'WRITTEN_OFF'].map((status) => isOpenDebt({ status })),
        [true, true, true, false, false, false, false]);
});

test('le plan d’une dette : mensualités, dernière plus petite, dernier mois', () => {
    assert.deepEqual(debtPlan('250000', '100000', '2026-10'), {
        count: 3, installment: '100000.00', last_amount: '50000.00', first_period: '2026-10', last_period: '2026-12',
    });
    assert.equal(debtPlan('', '100000', '2026-10'), null);
});

test('« Mes dettes » occupe toute la largeur, en shadcn, sans reliquat DashWind', () => {
    const mine = read(MINE);
    assert.match(mine, /<div class="w-full space-y-5">/);
    assert.doesNotMatch(mine, /max-w-5xl|mx-auto w-full max-w/);
    assert.doesNotMatch(mine, /Components\/UI\/(Icon|Button|Input)\.vue|\bni ni-|\bnk-/);
    assert.match(mine, /debtSteps\(debt\)/);
});

test('aucun composant de « Mes dettes » n’est utilisé sans être importé', () => {
    const BUILTINS = new Set(['Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense', 'Component', 'Head', 'Link']);
    const source = read(MINE);
    const cut = source.indexOf('<template>');
    const script = source.slice(0, cut);
    const missing = [...source.slice(cut).matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)]
        .map(([, name]) => name)
        .filter((name) => ! BUILTINS.has(name) && ! new RegExp(`\\b${name}\\b`).test(script));
    assert.deepEqual([...new Set(missing)], []);
    // Les icônes passées par :is ou :icon doivent aussi être importées.
    for (const [, name] of source.slice(cut).matchAll(/:(?:is|icon)="([A-Z][A-Za-z]+)"/g)) {
        assert.match(script, new RegExp(`\\b${name}\\b`), `${name} utilisé sans import`);
    }
});

/*
 * ADR-229 — les règles du site écrites comme le serveur (StaffDebtInterest, StaffDebtRules) :
 * l'aperçu pendant la saisie ne doit jamais annoncer un autre intérêt que celui qui sera figé.
 */
const TIERS = [
    { from: '0.00', to: '999999.99', mode: 'PERCENT', value: '5.00' },
    { from: '1000000.00', to: '4999999.99', mode: 'FIXED', value: '300000.00' },
    { from: '5000000.00', to: null, mode: 'PERCENT', value: '25.00' },
];
const money = (value) => `${Number(value).toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' ')} Ar`;

test('l’intérêt suit la tranche du montant, bornes comprises, comme le serveur', () => {
    assert.equal(interestFor('200000', TIERS).amount, '10000.00');
    assert.equal(interestFor('999999.99', TIERS).amount, '50000.00');
    assert.equal(interestFor('1000000', TIERS).amount, '300000.00');
    assert.equal(interestFor('1000000', TIERS).mode, 'FIXED');
    assert.equal(interestFor('8000000', TIERS).amount, '2000000.00');
    // 3,33 % de 123 457 Ar = 4 111,12 → arrondi à l'ariary : 4 111 (même calcul que Money::percentage).
    assert.equal(interestFor('123457', [{ from: '0', to: null, mode: 'PERCENT', value: '3.33' }]).amount, '4111.00');
    assert.equal(interestFor('50000', [{ from: '100000', to: null, mode: 'FIXED', value: '1000' }]), null);
    assert.equal(interestFor('', TIERS), null);
});

test('le total à rembourser ajoute l’intérêt une fois', () => {
    assert.deepEqual(totalWithInterest('1000000', TIERS).total, '1300000.00');
    assert.equal(totalWithInterest('1000000', []).interest, null);
    assert.equal(totalWithInterest('1000000', []).total, '1000000.00');
    assert.equal(debtPlan(totalWithInterest('1000000', TIERS).total, '325000', '2026-10').count, 4);
});

test('une tranche se lit en clair', () => {
    assert.equal(tierLabel(TIERS[0], money), 'de 0 Ar à 999 999,99 Ar : 5 %');
    assert.equal(tierLabel(TIERS[2], money), 'à partir de 5 000 000 Ar : 25 %');
    assert.equal(tierLabel(TIERS[1], money), 'de 1 000 000 Ar à 4 999 999,99 Ar : 300 000 Ar');
});

test('les limites du site se signalent pendant la saisie', () => {
    const rules = { min_amount: '50000.00', max_amount: '10000000.00', max_months: 12, max_salary_share: 40, interest_tiers: TIERS };

    assert.deepEqual(Object.keys(ruleIssues('20000', '10000', rules, money)), ['amount']);
    assert.deepEqual(Object.keys(ruleIssues('20000000', '3000000', rules, money)), ['amount']);
    // 1 300 000 Ar à rembourser (intérêt compris) en 12 mois au plus : 50 000 Ar ne suffisent pas.
    assert.match(ruleIssues('1000000', '50000', rules, money).installment_amount, /12 mois au plus : au moins 108 334 Ar/);
    // La mensualité que le salaire permet encore, servie par le serveur.
    assert.match(ruleIssues('1000000', '200000', rules, money, '160000.00').installment_amount, /Au plus 160 000 Ar par mois \(40 %/);
    assert.deepEqual(ruleIssues('1000000', '160000', rules, money, '160000.00'), {});
    assert.deepEqual(ruleIssues('1000000', '10', null, money), {});
});

test('les adresses des dettes suivent la base où l’écran est ouvert', () => {
    const base = '/super-admin/sites/A/finance/dettes';
    assert.equal(mapStaffDebtPath('/finance/dettes/reglages', base), `${base}/reglages`);
    assert.equal(mapStaffDebtPath('/finance/dettes?vue=a-verser', base), `${base}?vue=a-verser`);
    assert.equal(mapStaffDebtPath('/finance/dettes', base), base);
    assert.equal(mapStaffDebtPath('/finance/dettes-archives', base), '/finance/dettes-archives');
    assert.equal(mapStaffDebtPath('/mes-dettes', base), '/mes-dettes');
    assert.equal(mapStaffDebtPath('/finance/dettes/x', '/finance/dettes'), '/finance/dettes/x');
});

test('les écrans Finance des dettes n’utilisent aucun composant sans l’importer', () => {
    const BUILTINS = new Set(['Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense', 'Component', 'Head', 'Link']);
    for (const path of ['Pages/Finance/StaffDebts/Index.vue', 'Pages/Finance/StaffDebts/Show.vue', 'Pages/Finance/StaffDebts/Settings.vue',
        'Pages/SuperAdmin/Finance/StaffDebts.vue', 'Components/StaffDebts/StaffDebtPortalBar.vue', 'Components/StaffDebts/StaffDebtTermsFields.vue']) {
        const source = read(path);
        const cut = source.indexOf('<template>');
        const script = source.slice(0, cut);
        const missing = [...source.slice(cut).matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)]
            .map(([, name]) => name)
            .filter((name) => ! BUILTINS.has(name) && ! new RegExp(`\\b${name}\\b`).test(script));
        assert.deepEqual([...new Set(missing)], [], path);
        for (const [, name] of source.slice(cut).matchAll(/:(?:is|icon)="([A-Z][A-Za-z]+)"/g)) {
            assert.match(script, new RegExp(`\\b${name}\\b`), `${path} : ${name} utilisé sans import`);
        }
        // Aucune adresse de dette écrite en dur hors de staffDebtUrl (le portail la réécrit).
        assert.doesNotMatch(source, /['"`]\/administration\/dettes/, path);
    }
});

/*
 * ADR-234 — la demande ne porte que le montant, un motif facultatif et l'acceptation des
 * règles ; le remboursement est fixé par le DG.
 */
test('la demande de l’employé : le montant, les règles acceptées, sans mensualité ni premier mois', () => {
    const mine = read(MINE);
    const dialog = mine.slice(mine.indexOf('title="Demander une dette"'), mine.indexOf('<ConfirmModal'));
    assert.match(mine, /useForm\(\{ amount: '', reason: '', accept_terms: false, terms_version:/);
    assert.match(dialog, /v-model="form\.accept_terms"/);
    assert.match(dialog, /v-for="\(line, index\) in conditions"/);
    assert.match(dialog, /label="Motif" :icon="FileText" hint="\(facultatif\)"/);
    assert.doesNotMatch(dialog, /installment_amount|first_period|StaffDebtTermsFields/);
    // Seul le montant se vérifie à la saisie ; le serveur revérifie tout.
    assert.match(mine, /ruleIssues\(form\.amount, null, rules\.value, formatMoney\)\.amount/);
});

test('seul le montant se vérifie sans mensualité', () => {
    const rules = { min_amount: '50000.00', max_amount: '1000000.00', max_months: 12, interest_tiers: [] };
    assert.deepEqual(ruleIssues('300000', null, rules, money), {});
    assert.match(ruleIssues('20000', null, rules, money).amount, /minimum/);
    assert.equal(ruleIssues('20000', null, rules, money).installment_amount, undefined);
});

test('les durées proposées au DG respectent la durée maximale du site', () => {
    assert.deepEqual(quickMonths(null), [3, 6, 10, 12]);
    assert.deepEqual(quickMonths(8), [3, 6, 8]);
    assert.deepEqual(quickMonths(24), [3, 6, 10, 12, 24]);
    assert.deepEqual(quickMonths(2), [2]);
    assert.deepEqual(quickMonths(12), [3, 6, 10, 12]);
});
