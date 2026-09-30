import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { debtPlan, debtSteps, isOpenDebt, repaidShare } from '../../resources/js/utilities/staffDebts.js';

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

test('accordée, ajustée, attend le versement du RH', () => {
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
