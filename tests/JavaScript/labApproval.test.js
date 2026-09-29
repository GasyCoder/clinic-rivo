import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { approvalBadge, approvalSummary, awaitingApproval, itemApprovalLine, requestApprovalStatus } from '../../resources/js/utilities/labApproval.js';

/** ADR-216, amendement quater — la validation du médecin, mise en mots sans rien décider. */
const awaiting = { uuid: 'a', name: 'NFS', approval: { state: 'AWAITING', at: null, by: null } };
const approved = { uuid: 'b', name: 'Glycémie', approval: { state: 'APPROVED', at: '2026-09-29T10:00:00+03:00', by: 'Dr Rabe' } };

test('une analyse envoyée est « à valider », puis « validée »', () => {
    assert.equal(approvalBadge(awaiting).label, 'Terminée · à valider');
    assert.equal(approvalBadge(approved).label, 'Validée');
    assert.equal(approvalBadge({ approval: null }), null);
    assert.deepEqual(awaitingApproval([awaiting, approved]).map((item) => item.uuid), ['a']);
});

test('le résumé ne dit « tout validé » que si toutes les analyses envoyées le sont', () => {
    assert.equal(approvalSummary([awaiting, approved]).allApproved, false);
    const summary = approvalSummary([approved]);
    assert.equal(summary.allApproved, true);
    assert.deepEqual(summary.by, ['Dr Rabe']);
    assert.equal(approvalSummary([]).allApproved, false);
});

test('le statut d’une demande : « Terminé · à valider » ou « Résultats validés »', () => {
    assert.equal(requestApprovalStatus({ status: 'COMPLETED', approval: { awaiting: 1, approved: 1, total: 2 } }).detail, '1 sur 2 à valider');
    assert.equal(requestApprovalStatus({ status: 'COMPLETED', approval: { awaiting: 0, approved: 2, total: 2 } }).label, 'Résultats validés');
    assert.equal(requestApprovalStatus({ status: 'IN_PROGRESS', approval: { awaiting: 0, approved: 1, total: 2 } }), null);
    assert.equal(requestApprovalStatus({ status: 'CANCELLED', approval: { awaiting: 1, approved: 0, total: 1 } }), null);
    assert.equal(requestApprovalStatus({ status: 'COMPLETED', approval: null }), null);
});

test('la ligne sous une analyse nomme qui a validé ; une correction n’en a pas', () => {
    assert.match(itemApprovalLine(approved).text, /Validé par Dr Rabe/);
    assert.equal(itemApprovalLine({ ...awaiting, in_correction: true }), null);
});

test('le médecin valide depuis sa feuille ; la Réception a son écran', () => {
    const print = fs.readFileSync('resources/js/Pages/Laboratory/ResultsPrint.vue', 'utf8');
    assert.match(print, /\/resultats-analyses\/\$\{props\.labRequest\.uuid\}\/valider/);
    assert.match(print, /can\.approve/);
    const requests = fs.readFileSync('resources/js/Pages/Medicine/Requests.vue', 'utf8');
    assert.match(requests, /key: 'to_validate'/);
    assert.match(requests, /Vérifier et valider/);
    const workspaces = fs.readFileSync('resources/js/utilities/clinicWorkspaces.js', 'utf8');
    assert.match(workspaces, /link: '\/reception\/resultats-analyses', permission: 'laboratory_results\.validated_view'/);
});

test('le compte rendu dit son état sur une ligne sobre, sans bandeau coloré', () => {
    const print = fs.readFileSync('resources/js/Pages/Laboratory/ResultsPrint.vue', 'utf8');
    assert.doesNotMatch(print, /bg-(amber|emerald)-50\b/, 'l’état du compte rendu ne doit plus être un bandeau coloré');
    assert.match(print, /Analyses du compte rendu/);
    assert.match(print, /lg:grid-cols-\[20rem_minmax\(0,1fr\)\]/);
});
