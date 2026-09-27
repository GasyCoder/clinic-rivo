import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    closureAction, closureMissing, decisionIsLocked, decisionPayload, decisionSummary, followUpDate,
} from '../../resources/js/utilities/consultationClosure.js';

const page = fs.readFileSync('resources/js/Pages/Medicine/Show.vue', 'utf8');
const panel = fs.readFileSync('resources/js/Components/Clinical/ConsultationDecisionPanel.vue', 'utf8');

/**
 * « Décision & clôture » (ADR-203).
 *
 * Demande du propriétaire : « le bouton Clôturer ne doit dépendre d'aucune
 * information — on clôture dès que le médecin a choisi la conduite à tenir »,
 * « trop flou, trop de redondance, double transfert et référence », « rien de
 * conditions avec « Le diagnostic peut-il être posé maintenant ? » ».
 */

test('la clôture tient sur un seul écran, sans sous-étapes ni question sur le diagnostic', () => {
    assert.doesNotMatch(page, /closureSubStep/);
    assert.doesNotMatch(page, /diagnosisTimingForm|decideDiagnosisTiming|diagnosisReady/);
    assert.doesNotMatch(page, /Le diagnostic peut-il être posé maintenant/);

    // Deux sections, dans l'ordre : le diagnostic (facultatif), la conduite.
    const diagnosis = page.indexOf('id="closure-diagnosis"');
    const decision = page.indexOf('id="closure-decision"');
    assert.ok(diagnosis !== -1 && decision > diagnosis);
    assert.match(page.slice(diagnosis, decision), /\(facultatif\)/);
    assert.match(page, /<ConsultationDecisionPanel/);
});

test('seule la conduite à tenir retient « Clôturer »', () => {
    assert.match(page, /:disabled="Boolean\(closureHint\) \|\| decisionForm\.processing"/);

    // Aucun diagnostic, aucune étape validée : une conduite suffit.
    assert.equal(closureMissing({ type: 'DISCHARGE' }), null);
    assert.equal(closureMissing({ type: 'HOSPITALIZATION' }), null);
    assert.equal(closureMissing({ type: null }), 'Choisissez la conduite à tenir.');
    // Le bloc ne programme rien sans intervention (ADR-114, ADR-159).
    assert.match(closureMissing({ type: 'SURGERY', catalog_item_uuid: '' }), /intervention/);
    assert.equal(closureMissing({ type: 'SURGERY', catalog_item_uuid: 'uuid' }), null);

    // Une conduite déjà transmise, ou une sortie déjà prononcée, suffit aussi.
    assert.equal(closureMissing({ type: null }, { active: { status: 'SUBMITTED' } }), null);
    assert.equal(closureMissing({ type: null }, { medicalDischarge: { type: 'NORMAL' } }), null);
});

test('ce qui part ne porte que les champs de la conduite choisie', () => {
    const discharge = decisionPayload({
        type: 'DISCHARGE', discharge_type: 'NORMAL', patient_condition: 'Guéri',
        discharge_prescription: '  Amoxicilline  ', recommendations: '', follow_up_at: '2026-10-04',
        priority: 'URGENT', notes: 'x', catalog_item_uuid: 'u', facility: 'f',
    });
    assert.deepEqual(discharge, {
        type: 'DISCHARGE', discharge_type: 'NORMAL', patient_condition: 'Guéri',
        discharge_prescription: 'Amoxicilline', recommendations: null, follow_up_at: '2026-10-04',
    });

    // Un décès : aucune consigne n'a de destinataire (ADR-107).
    assert.deepEqual(decisionPayload({ type: 'DISCHARGE', discharge_type: 'DECEASED', recommendations: 'Repos' }), {
        type: 'DISCHARGE', discharge_type: 'DECEASED',
    });

    assert.deepEqual(decisionPayload({ type: 'SURGERY', catalog_item_uuid: 'abc', priority: 'NORMAL', notes: ' ' }), {
        type: 'SURGERY', priority: 'NORMAL', notes: null, catalog_item_uuid: 'abc',
    });
    assert.deepEqual(decisionPayload({ type: 'REFERRAL', facility: '', priority: 'URGENT', notes: '' }), {
        type: 'REFERRAL', priority: 'URGENT', notes: null, facility: null,
    });

    // Une conduite déjà fixée ne se renvoie pas.
    assert.deepEqual(decisionPayload({ type: 'SURGERY' }, { active: { status: 'SUBMITTED' } }), {});
    assert.equal(decisionIsLocked({ active: { status: 'SELECTED' } }), false);
});

test('le transfert est une conduite, jamais un type de sortie', () => {
    // Les types de sortie viennent du serveur, sans « Transfert » (forConsultation).
    assert.match(page, /:discharge-types="options\.discharge_types \?\? \[\]"/);
    assert.doesNotMatch(panel, /'TRANSFER'/);
    assert.doesNotMatch(panel, /transfer_destination/);
});

test('la fenêtre de clôture relit ce qui part', () => {
    const lines = decisionSummary(
        { type: 'SURGERY', catalog_item_uuid: 'a1', priority: 'URGENT', notes: 'À jeun' },
        {
            types: [{ value: 'SURGERY', label: 'Chirurgie' }],
            priorities: [{ value: 'URGENT', label: 'Urgent' }],
            surgeryCatalog: [{ uuid: 'a1', name: 'Appendicectomie' }],
        },
    );
    assert.deepEqual(lines, [
        { label: 'Conduite à tenir', value: 'Chirurgie' },
        { label: 'Intervention envisagée', value: 'Appendicectomie' },
        { label: 'Priorité', value: 'Urgent' },
        { label: 'Consignes', value: 'À jeun' },
    ]);

    const referral = decisionSummary({ type: 'REFERRAL', facility: '' }, { types: [{ value: 'REFERRAL', label: 'Référence / Transfert' }] });
    assert.deepEqual(referral[1], { label: 'Établissement', value: 'À préciser dans Transferts' });

    const discharge = decisionSummary({ type: 'DISCHARGE', discharge_type: 'NORMAL', follow_up_at: '2026-10-04' }, {});
    assert.deepEqual(discharge.at(-1), { label: 'Contrôle', value: '04/10/2026' });
});

/**
 * Demande du propriétaire (2026-09-27) : transmettre le patient à un service
 * doit se lire sur le bouton, avant la clôture finale — jamais un « Clôturer »
 * qui tairait l'envoi. Une sortie ne transmet à personne : le libellé ne le
 * prétend pas.
 */
test('le bouton dit ce qu’il fait : transmettre, prononcer une sortie, ou clôturer', () => {
    const maternity = closureAction({ type: 'MATERNITY' });
    assert.equal(maternity.transmits, true);
    assert.equal(maternity.button, 'Transmettre et clôturer');
    assert.equal(maternity.confirm, 'Je transmets ce patient à la Maternité et je clôture');
    assert.match(maternity.notice, /transmis à la Maternité/);

    assert.equal(closureAction({ type: 'SURGERY' }).confirm, 'Je transmets ce patient au bloc opératoire et je clôture');
    assert.equal(closureAction({ type: 'REFERRAL' }).title, 'Transmettre aux Transferts et clôturer');

    const discharge = closureAction({ type: 'DISCHARGE' });
    assert.equal(discharge.transmits, false);
    assert.equal(discharge.confirm, 'Je prononce la sortie et je clôture');
    assert.equal(closureAction({ type: 'DISCHARGE', discharge_type: 'DECEASED' }).confirm, 'Je prononce le décès et je clôture');

    // Rien à transmettre : déjà partie, ou le patient reste au lit.
    assert.equal(closureAction({ type: 'MATERNITY' }, { active: { status: 'SUBMITTED' } }).confirm, 'Je confirme et clôture');
    assert.equal(closureAction({ type: 'CONTINUED_HOSPITALIZATION' }).transmits, false);

    // L'écran lit ces libellés, il n'en écrit aucun lui-même.
    assert.match(page, /closureActionLabels\.button/);
    assert.match(page, /closureActionLabels\.confirm/);
    assert.match(page, /:title="closureActionLabels\.title"/);
});

test('un contrôle « dans N jours » se compte dans le fuseau du poste', () => {
    assert.equal(followUpDate(7, new Date(2026, 8, 27, 23, 30)), '2026-10-04');
});

test('la conduite préparée survit à une actualisation', () => {
    assert.match(page, /decision: decisionForm,/);

    const request = fs.readFileSync('app/Http/Requests/Medicine/SaveConsultationDraftRequest.php', 'utf8');
    assert.match(request, /'decision',/);
});

/**
 * ADR-081 — correction et retrait d'un diagnostic restent à la clôture : une
 * faute de frappe se rectifie là où on la relit.
 */
test('un diagnostic enregistré peut être corrigé et retiré', () => {
    const list = fs.readFileSync('resources/js/Components/Clinical/ClinicalDiagnosisList.vue', 'utf8');

    assert.match(list, /\.put\(`\/medicine\/orientations\/\$\{props\.orientationUuid\}\/diagnoses`/);
    assert.match(list, /\.post\(`\/medicine\/orientations\/\$\{props\.orientationUuid\}\/diagnoses\/cancel`/);
    assert.match(list, /v-if="diagnosis\.can_edit"/);
    assert.match(list, /v-if="diagnosis\.can_cancel"/);
    assert.match(list, /:dismissible="false"/);
    // Le diagnostic n'est plus exigé : rien n'avertit plus que la clôture en dépend.
    assert.doesNotMatch(list, /requiredForClosure/);
    assert.match(page, /<ClinicalDiagnosisList/);
});
