import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    anesthesiaNextAction,
    anesthesiaSteps,
    openingStep,
    statusRank,
    surgeryNextAction,
    surgerySteps,
} from '../../resources/js/utilities/surgicalWorkflow.js';

const page = fs.readFileSync('resources/js/Pages/Surgery/Show.vue', 'utf8');
const preoperative = fs.readFileSync('resources/js/Components/Surgery/ValidationPreoperatoire.vue', 'utf8');

const request = (overrides = {}) => ({
    status: 'PENDING',
    surgeon: null,
    scheduled_at: null,
    preoperative_validated_at: null,
    block_entry: null,
    intervention: null,
    block_exit: null,
    report: null,
    ...overrides,
});

test('la prochaine action suit exactement les transitions du modèle', () => {
    assert.equal(surgeryNextAction(request()).key, 'schedule');
    assert.equal(surgeryNextAction(request({ status: 'SCHEDULED' })).key, 'preoperative');
    assert.equal(surgeryNextAction(request({ status: 'PREOPERATIVE_VALIDATED' })).key, 'start');
    assert.equal(surgeryNextAction(request({ status: 'IN_PROGRESS', intervention: { ended_at: null } })).key, 'end');
    assert.equal(surgeryNextAction(request({ status: 'IN_PROGRESS', intervention: { ended_at: '2026-09-22 10:00' } })).key, 'block-exit');
    assert.equal(surgeryNextAction(request({ status: 'IN_PROGRESS', intervention: { ended_at: 'x' }, block_exit: {} })).key, 'report');
    assert.equal(surgeryNextAction(request({ status: 'IN_PROGRESS', intervention: { ended_at: 'x' }, block_exit: {}, report: {} })).key, 'validate-report');
    // La sortie de Chirurgie verrouille la sortie du bloc : elle vient d'abord.
    assert.equal(surgeryNextAction(request({ status: 'COMPLETED' })).key, 'block-exit');
    assert.equal(surgeryNextAction(request({ status: 'COMPLETED', block_exit: {} })).key, 'discharge');
    assert.equal(surgeryNextAction(request({ status: 'DISCHARGED' })).key, null);
    assert.equal(surgeryNextAction(request({ status: 'CANCELLED' })).key, null);
});

test('chaque action nomme le droit que le serveur exigera', () => {
    assert.equal(surgeryNextAction(request()).permission, 'surgery.schedule');
    assert.equal(surgeryNextAction(request({ status: 'SCHEDULED' })).permission, 'surgery.preoperative.validate');
    assert.equal(surgeryNextAction(request({ status: 'PREOPERATIVE_VALIDATED' })).permission, 'surgery.intervention.create');
    assert.equal(surgeryNextAction(request({ status: 'COMPLETED', block_exit: {} })).permission, 'surgery.discharge.create');
});

test('une étape en attente dit pourquoi, sans être verrouillée à la lecture', () => {
    const steps = surgerySteps(request());
    const byId = Object.fromEntries(steps.map((step) => [step.id, step]));

    assert.equal(byId.case.waiting, null);
    assert.match(byId.preparation.waiting, /programmée/);
    assert.match(byId.intervention.waiting, /feu vert/);
    assert.match(byId.followup.waiting, /intervention démarrée/);

    const inProgress = Object.fromEntries(surgerySteps(request({ status: 'IN_PROGRESS', intervention: {} })).map((step) => [step.id, step]));
    for (const id of ['preparation', 'intervention', 'block-exit', 'followup']) assert.equal(inProgress[id].waiting, null);
});

test('le dossier s’ouvre sur l’étape de sa prochaine action', () => {
    const open = (overrides) => {
        const value = request(overrides);

        return openingStep(surgerySteps(value), surgeryNextAction(value), value.status === 'CANCELLED');
    };

    assert.equal(open({}), 'case');
    assert.equal(open({ status: 'SCHEDULED' }), 'preparation');
    assert.equal(open({ status: 'PREOPERATIVE_VALIDATED' }), 'intervention');
    assert.equal(open({ status: 'IN_PROGRESS', intervention: { ended_at: 'x' } }), 'block-exit');
    assert.equal(open({ status: 'COMPLETED' }), 'block-exit');
    assert.equal(open({ status: 'COMPLETED', block_exit: {} }), 'followup');
    assert.equal(open({ status: 'DISCHARGED' }), 'followup');
    assert.equal(open({ status: 'CANCELLED' }), 'case');
});

test('le rang d’une demande annulée reste hors du workflow', () => {
    assert.equal(statusRank('CANCELLED'), -1);
    assert.ok(statusRank('IN_PROGRESS') > statusRank('PREOPERATIVE_VALIDATED'));
});

test('l’anesthésie suit consultation, bilan, décision, conduite', () => {
    const assessed = { consultation_data: { history: 'HTA' }, assessment_validated_at: 'x' };

    assert.equal(anesthesiaNextAction(null).key, 'consultation');
    assert.equal(anesthesiaNextAction(null).permission, 'anesthesia.create');
    assert.equal(anesthesiaNextAction({ consultation_data: {} }).permission, 'anesthesia.update');
    assert.equal(anesthesiaNextAction({ consultation_data: { history: 'HTA' } }).key, 'assessment');

    // ADR-170 — valider l'évaluation ne vaut pas autorisation : la décision
    // reste à prononcer, et c'est elle que le bloc attend.
    assert.equal(anesthesiaNextAction(assessed).key, 'clearance');
    assert.equal(anesthesiaNextAction({ ...assessed, clearance_status: 'DRAFT' }).key, 'clearance');
    assert.equal(anesthesiaNextAction({ ...assessed, clearance_status: 'NOT_CLEARED' }).key, 'peroperative');
    assert.equal(anesthesiaNextAction({ ...assessed, clearance_status: 'CLEARED', validated_at: 'y' }).key, null);

    const [consultation, paraclinical, clearance, peroperative] = anesthesiaSteps({ consultation_data: { history: 'HTA' } });
    assert.equal(consultation.complete, true);
    assert.equal(paraclinical.waiting, null);
    assert.equal(clearance.complete, false);
    assert.match(clearance.waiting, /bilan terminé/);
    assert.match(peroperative.waiting, /validation de l’évaluation/);

    // Une décision prononcée, quelle qu'elle soit, termine son étape.
    const [, , decided] = anesthesiaSteps({ ...assessed, clearance_status: 'NOT_CLEARED' });
    assert.equal(decided.complete, true);
});

test('le compte rendu ne se valide pas sans l’heure de fin, et jamais hors du bloc', () => {
    assert.match(page, /reportValidationBlocker/);
    assert.match(page, /status\.value !== 'IN_PROGRESS'/);
    assert.match(page, /!intervention\.value\?\.ended_at/);
    // La sortie ne s'enregistre qu'une fois l'intervention close.
    assert.match(page, /status === 'COMPLETED' && can\('surgery\.discharge\.create'\)/);
    assert.match(page, /La sortie du bloc n’est pas renseignée/);
});

test('un droit manquant est nommé plutôt qu’un bouton qui refuserait', () => {
    assert.match(page, /À faire par un compte disposant du droit « \$\{next\.value\.permission\} »/);
    assert.match(page, /nextAllowed/);
});

test('le feu vert dit pourquoi il attend', () => {
    assert.match(preoperative, /Programmez d’abord l’intervention/);
    assert.match(preoperative, /Enregistrez d’abord les observations/);
    assert.doesNotMatch(preoperative, /Components\/UI\//);
});

test('la page n’utilise plus la police d’icônes ni la palette DashWind', () => {
    assert.doesNotMatch(page, /SurgeryIcon|<Icon /);
    assert.doesNotMatch(page, /text-slate-|bg-slate-|border-gray-|dark:bg-gray-/);
});

const inOrder = (block, needles) => {
    const positions = needles.map((needle) => block.indexOf(needle));

    return positions.every((position) => position >= 0) && positions.every((position, index) => index === 0 || position > positions[index - 1]);
};
const between = (text, from, to) => {
    const start = text.indexOf(from);

    return text.slice(start, text.indexOf(to, start + from.length));
};

test('deux colonnes dans l’espace Chirurgie : le geste à gauche, le contexte à droite', () => {
    // Une barre verticale à glisser entre les deux panneaux ; l'Anesthésie garde un seul panneau.
    assert.match(page, /<ResizableSplit\s+:single="isAnesthesiaWorkspace"\s+storage-key="rivo:surgery:context-split"/);
    assert.ok(inOrder(page, ['<template #start>', '<main class="min-w-0 space-y-4">', '<template #end>', '<aside v-if="!isAnesthesiaWorkspace"']));
    const aside = between(page, '<aside v-if="!isAnesthesiaWorkspace"', '</aside>');

    // Le même contexte à chaque étape, dans l'ordre où on le relit.
    assert.ok(inOrder(aside, ['title="Synthèse anesthésie"', '<SurgicalRequestCard', '<CareSummaryReadOnly v-if="careSummary" dense compact']));
    assert.doesNotMatch(aside, /<HospitalStayBanner/);
    assert.doesNotMatch(aside, /title="Équipe de bloc"/);
    // Le patient hospitalisé se dit en pastille à côté du statut, dans les deux espaces.
    assert.match(page, /<template #status>[\s\S]*<HospitalStayBanner v-if="hospitalStay" inline/);
    assert.equal((page.match(/<HospitalStayBanner/g) ?? []).length, 1);
});

test('chaque étape ne montre que son geste, dans l’ordre du workflow', () => {
    const dossier = between(page, "activeTab === 'case'\">", "activeTab === 'preparation'\">");
    // Programmer, puis composer l'équipe juste en dessous : ses chirurgiens viennent de la programmation.
    assert.ok(inOrder(dossier, ['title="Programmation"', 'title="Équipe de bloc"']));
    assert.match(dossier, /waiting-label="Après la programmation"/);
    assert.doesNotMatch(dossier, /SurgicalRequestCard|Synthèse anesthésie/);

    const preparation = between(page, "activeTab === 'preparation'\">", "activeTab === 'intervention'\">");
    assert.ok(inOrder(preparation, ['<ValidationPreoperatoire', '<EntreeBloc']));
    assert.doesNotMatch(preparation, /Synthèse anesthésie/);

    const followup = between(page, "activeTab === 'followup'\">", '</main>');
    assert.ok(inOrder(followup, ['title="Compte rendu opératoire"', 'title="Suivi péri- et postopératoire"', 'title="Complications"', 'title="Sortie de Chirurgie"']));
});

test('la carte Demande se lit avec un repère par fait et se corrige sur place', () => {
    const card = fs.readFileSync('resources/js/Components/Surgery/SurgicalRequestCard.vue', 'utf8');

    for (const label of ['Intervention demandée', 'Demandée par', 'Transmission au bloc']) assert.match(card, new RegExp(label));
    for (const icon of ['<Scissors', '<UserRound', '<MessageSquareText', '<CalendarClock']) assert.ok(card.includes(icon), icon);
    // Rien à enregistrer tant que rien n'a changé ; « Autres » exige sa précision.
    assert.match(card, /:disabled="form\.processing \|\| !form\.isDirty"/);
    assert.match(card, /OTHER_CODE = 'SURG-OTHER'/);
    assert.match(card, /\.put\(`\/surgery\/\$\{props\.surgicalRequest\.uuid\}`/);
    assert.doesNotMatch(card, /Components\/UI\/Icon|text-slate-|bg-slate-/);
});
