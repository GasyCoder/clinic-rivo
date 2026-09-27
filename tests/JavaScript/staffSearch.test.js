import test from 'node:test';
import assert from 'node:assert/strict';
import { highlight, normalizeText, searchStaff } from '../../resources/js/utilities/staffSearch.js';

const STAFF = [
    { uuid: 'a', name: 'Andry Rakoto', employee_number: 'RH-012', job_title: 'Infirmier', department: 'Soins', email: 'andry@clinique.mg' },
    { uuid: 'b', name: 'Zéphyr Haynes', employee_number: 'RD', job_title: 'Médecin', department: 'Médecine', email: null },
    { uuid: 'c', name: 'Vola Randria', employee_number: 'RH-013', job_title: 'Sage-femme', department: 'Maternité', email: 'vola@clinique.mg', account: { uuid: 'u-9', name: 'Vola R.' } },
    { uuid: 'd', name: 'Hery Andrianina', employee_number: 'RH-014', job_title: 'Médecin', department: 'Médecine', email: null },
];

test('les accents et la casse ne comptent pas', () => {
    assert.equal(normalizeText('Zéphyr MÉDECIN'), 'zephyr medecin');
    assert.deepEqual(searchStaff(STAFF, 'zephyr').results.map((e) => e.uuid), ['b']);
    assert.deepEqual(searchStaff(STAFF, 'MEDECINE').results.map((e) => e.uuid), ['b', 'd']);
});

test('tous les mots tapés, dans n’importe quel ordre, sur le nom, le matricule, la fonction, le service ou l’email', () => {
    assert.deepEqual(searchStaff(STAFF, 'soins andry').results.map((e) => e.uuid), ['a']);
    assert.deepEqual(searchStaff(STAFF, 'rh-013').results.map((e) => e.uuid), ['c']);
    assert.deepEqual(searchStaff(STAFF, 'vola@').results.map((e) => e.uuid), ['c']);
    assert.deepEqual(searchStaff(STAFF, 'andry medecin').results, []);
});

test('un nom qui commence par ce qu’on tape remonte devant une simple occurrence', () => {
    // « andr » : Andry commence par… ; Andrianina aussi ; Randria le contient seulement.
    assert.deepEqual(searchStaff(STAFF, 'andr').results.map((e) => e.uuid), ['a', 'd', 'c']);
});

test('une fiche déjà reliée à un autre compte reste listée, après les libres', () => {
    const isTaken = (employee) => Boolean(employee.account);
    assert.deepEqual(searchStaff(STAFF, '', { isTaken }).results.map((e) => e.uuid), ['a', 'b', 'd', 'c']);
});

test('la liste est bornée, et le total dit combien d’autres fiches correspondent', () => {
    const many = Array.from({ length: 20 }, (_, index) => ({ uuid: `x${index}`, name: `Agent ${index}`, employee_number: `N${index}` }));
    const { results, total } = searchStaff(many, 'agent', { limit: 8 });
    assert.equal(results.length, 8);
    assert.equal(total, 20);
});

test('le surlignage ignore les accents pour comparer, jamais pour afficher', () => {
    assert.deepEqual(highlight('Zéphyr Haynes', 'zep'), [{ text: 'Zép', match: true }, { text: 'hyr Haynes', match: false }]);
    assert.deepEqual(highlight('Andry Rakoto', 'rak andry'), [
        { text: 'Andry', match: true }, { text: ' ', match: false }, { text: 'Rak', match: true }, { text: 'oto', match: false },
    ]);
    assert.deepEqual(highlight('Andry', ''), [{ text: 'Andry', match: false }]);
});
