import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    bankLabel, categoryCaption, codeFrom, fieldsFor, isGenericBankMethod, matchesSearch, missingFields, payloadFor, suggestedCode,
} from '../../resources/js/utilities/paymentMethods.js';

const banks = [{ uuid: 'b-1', code: 'BOA', name: 'Bank of Africa' }];

test('a bank method asks for a bank, an other method for its category, the rest for a label', () => {
    assert.deepEqual(fieldsFor('BANK'), { name: false, bank: true, detail: false });
    assert.deepEqual(fieldsFor('OTHER'), { name: true, bank: false, detail: true });
    assert.deepEqual(fieldsFor('CASH'), { name: true, bank: false, detail: false });

    assert.deepEqual(missingFields({ code: 'X', category: 'BANK', name: '' }), ['la banque']);
    assert.deepEqual(missingFields({ code: 'X', category: 'BANK', bank_uuid: 'b-1', name: '' }), []);
    assert.deepEqual(missingFields({ code: 'X', category: 'OTHER', name: 'TPE', category_detail: '' }), ['la précision de la catégorie']);
    assert.deepEqual(missingFields({ code: '', category: 'CASH', name: '' }), ['le code', 'le libellé']);
});

test('a generic method already in service is not forced to choose a bank or a detail', () => {
    const check = { category: 'BANK', bank_uuid: null };
    assert.equal(isGenericBankMethod(check), true);
    assert.deepEqual(missingFields({ category: 'BANK', name: 'Chèque' }, { editing: check }), []);
    assert.deepEqual(missingFields({ category: 'OTHER', name: 'Autre' }, { editing: { category: 'OTHER', category_detail: null } }), []);
});

test('only the fields of the chosen category leave for the site', () => {
    const form = { site_code: 'A', code: 'BANK_BOA', name: ' ', category: 'BANK', bank_uuid: 'b-1', category_detail: 'stale', affects_cash_balance: 0, requires_reference: 1 };
    assert.deepEqual(payloadFor(form), {
        site_code: 'A', code: 'BANK_BOA', name: null, category: 'BANK', bank_uuid: 'b-1', affects_cash_balance: false, requires_reference: true,
    });

    const other = payloadFor({ ...form, category: 'OTHER', name: 'Carte au TPE', category_detail: ' Carte bancaire ' }, { editing: { uuid: 'm' } });
    assert.equal(other.bank_uuid, undefined);
    assert.equal(other.category_detail, 'Carte bancaire');
    assert.equal(other.code, undefined, 'le code ne change plus après la création');
});

test('the code is proposed from the bank, the detail or the label', () => {
    assert.equal(codeFrom('Carte bancaire à puce'), 'CARTE_BANCAIRE_A_PUCE');
    assert.equal(suggestedCode({ category: 'BANK', bank_uuid: 'b-1' }, banks), 'BANK_BOA');
    assert.equal(suggestedCode({ category: 'OTHER', category_detail: 'Bon d’achat' }, banks), 'OTHER_BON_D_ACHAT');
    assert.equal(suggestedCode({ category: 'MOBILE_MONEY', name: 'MVola' }, banks), 'MOBILE_MONEY_MVOLA');
    assert.equal(bankLabel(banks[0]), 'BOA — Bank of Africa');
});

test('the list reads the bank and the detail, and the search ignores accents', () => {
    assert.equal(categoryCaption({ category: 'BANK', bank: { code: 'BOA' }, category_label: 'Banque' }), 'Banque · BOA');
    assert.equal(categoryCaption({ category: 'BANK', bank: null, category_label: 'Banque' }), 'Banque · toutes banques');
    assert.equal(categoryCaption({ category: 'OTHER', category_detail: 'Carte bancaire', category_label: 'Autre' }), 'Autre · Carte bancaire');
    assert.equal(matchesSearch({ name: 'Chèque', code: 'CHECK', bank: { code: 'BOA', name: 'Bank of Africa' } }, 'cheque africa'), true);
    assert.equal(matchesSearch({ name: 'Espèces', code: 'CASH' }, 'boa'), false);
});

test('the payment methods page is written with shadcn and offers the bank list and the free category', () => {
    const page = fs.readFileSync(new URL('../../resources/js/Pages/SuperAdmin/PaymentMethods/Index.vue', import.meta.url), 'utf8');
    assert.match(page, /<SearchSelect[\s\S]*?v-model="form\.bank_uuid"/);
    assert.match(page, /v-if="fields\.detail"[\s\S]*?v-model="form\.category_detail"/);
    assert.match(page, /<Dialog/);
    assert.doesNotMatch(page, /<select\b|<input\b/, 'aucun contrôle natif habillé à la main');
});
