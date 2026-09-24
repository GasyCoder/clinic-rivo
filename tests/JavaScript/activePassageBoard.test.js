import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {
    boardParams,
    elapsedFrom,
    elsewhereLabel,
    emptyState,
    entryPathHint,
    needIcon,
    needSummary,
    stateIcon,
    stateTone,
    visibleNeeds,
    waitTone,
} from '../../resources/js/utilities/activePassages.js';
import { Activity, Bandage, CircleCheck, ClipboardList, Clock, Hourglass, ScanLine, Stethoscope } from 'lucide-vue-next';
import { orderedNextSteps, toggleNextStep } from '../../resources/js/utilities/nextSteps.js';

const read = (file) => fs.readFileSync(file, 'utf8');
const board = read('resources/js/Components/Clinical/ActivePassageBoard.vue');
const picker = read('resources/js/Components/Reception/NextStepPicker.vue');
const reception = read('resources/js/Pages/Reception/Create.vue');
const services = read('resources/js/Pages/Reception/EpisodeServices.vue');
const card = read('resources/js/Components/Reception/EpisodeNextStepsCard.vue');
const episode = read('resources/js/Pages/Episodes/Show.vue');
const entryPath = read('resources/js/Components/Clinical/EntryPathConfirm.vue');
const skipGuard = read('resources/js/composables/useQueueSkipGuard.js');

/**
 * ADR-177 — le besoin, la suggestion, la visibilité et la prise en charge sont
 * quatre notions séparées. L'écran affiche ce que le serveur décide ; il ne
 * recalcule aucune règle.
 */
const OPTIONS = [
    { value: 'CARE', label: 'Soins' },
    { value: 'MEDICINE', label: 'Médecine' },
    { value: 'PHARMACY', label: 'Pharmacie' },
];

test('la prochaine étape suggérée se lit dans l’ordre des options, jamais dans celui des clics', () => {
    assert.deepEqual(orderedNextSteps(['PHARMACY', 'CARE'], OPTIONS), ['CARE', 'PHARMACY']);
    assert.deepEqual(toggleNextStep(['MEDICINE'], 'CARE', true, OPTIONS), ['CARE', 'MEDICINE']);
    assert.deepEqual(toggleNextStep(['CARE', 'MEDICINE'], 'CARE', false, OPTIONS), ['MEDICINE']);
    // Une sélection vide est une réponse valide.
    assert.deepEqual(toggleNextStep(['CARE'], 'CARE', false, OPTIONS), []);
});

test('la suggestion est facultative et le dit, sans jamais d’erreur « obligatoire »', () => {
    assert.match(picker, /Cette information est indicative\. Elle n’empêche pas les autres services autorisés de voir le passage\./);
    assert.match(picker, /Facultatif/);
    assert.match(picker, /Aucune suggestion : c’est un état normal/);
    // Le gabarit seul : le commentaire du composant explique justement pourquoi le mot n'y figure pas.
    assert.doesNotMatch(picker.slice(picker.indexOf('<template>')), /required|obligatoire/i);
    // Les choix viennent du serveur : aucune liste recopiée.
    assert.match(picker, /options: \{ type: Array/);
    assert.doesNotMatch(picker, /value: 'CARE'/);
});

test('l’accueil compte six étapes, sans « Routage », et envoie la suggestion à la confirmation', () => {
    const labels = [...reception.matchAll(/\{ number: \d, label: '([^']+)' \}/g)].map((match) => match[1]);

    assert.deepEqual(labels, ['Besoin', 'Estimation', 'Patient', 'Passage', 'Prise en charge', 'Confirmation']);
    assert.doesNotMatch(reception, /label: 'Routage'|Destination initiale|routing_label/);
    assert.match(reception, /<NextStepPicker v-model="nextSteps"/);
    assert.match(reception, /finalForm\.next_steps = \[\.\.\.nextSteps\.value\]/);
    assert.match(services, /<NextStepPicker v-model="form\.next_steps"/);
    assert.doesNotMatch(services, /Parcours calculé|routeLabels/);
});

test('la suggestion se corrige depuis le détail du passage, par la route dédiée', () => {
    assert.match(episode, /<EpisodeNextStepsCard/);
    assert.match(episode, /:can-update="Boolean\(capabilities\.can_update_next_steps\)"/);
    assert.match(card, /router\.put\(`\/reception\/passages\/\$\{props\.episodeUuid\}\/prochaines-etapes`, \{ next_steps: draft\.value \}/);
    assert.match(card, /Aucune suggestion — c’est un état normal\./);
});

test('le tableau affiche la suggestion, et « Aucune suggestion » est un état neutre', () => {
    assert.match(board, /v-if="row\.next_steps\.length"/);
    assert.match(board, /<CircleDashed class="h-3\.5 w-3\.5 shrink-0" aria-hidden="true" \/>Aucune suggestion/);
});

test('voir le passage n’est pas le prendre en charge : deux gestes distincts, donnés par le serveur', () => {
    // Libellé court « Prendre », le geste complet reste nommé pour le survol et les lecteurs d'écran.
    assert.match(board, /aria-label="Prendre en charge"/);
    assert.match(board, /'Prendre' \}\}/);
    assert.match(board, /Voir le passage/);
    for (const action of ['take_charge_url', 'open_url', 'release_url', 'passage_url']) {
        assert.match(board, new RegExp(`row\\.actions\\.${action}`), `${action} manque`);
    }
    // Aucun droit de prise en charge n'est recalculé à l'écran.
    assert.doesNotMatch(board, /can\('(care|consultations|maternity)\./);
});

test('un passage terminé se relit : journal et dossier, et le passage a sa vraie icône', () => {
    assert.match(board, /v-if="row\.actions\.journal_url"/);
    assert.match(board, /v-if="row\.actions\.medical_record_url"/);
    assert.match(board, /aria-label="Voir le passage">\s*<FolderClock /);
    assert.match(board, /aria-label="Dossier médical">\s*<FileHeart /);
    assert.match(board, /aria-label="Journal de traitement">\s*<NotebookPen /);
});

test('les gestes d’une ligne tiennent sur une seule ligne : libellé court pour le travail, icônes groupées pour la relecture', () => {
    assert.match(board, /<div class="flex flex-nowrap items-center justify-end gap-2">/);
    assert.match(board, /const ACTION_CLASS = 'h-8 gap-1\.5 px-2\.5 text-xs';/);
    // Libellé court à l'écran, libellé complet pour le survol et les lecteurs d'écran.
    assert.match(board, /aria-label="Remettre en file"/);
    assert.match(board, /<Undo2 class="h-3\.5 w-3\.5" aria-hidden="true" \/>Remettre\n/);
    // La relecture est une barre d'icônes nommée, jamais une suite de boutons libellés.
    assert.match(board, /role="group"\s+aria-label="Relire"/);
    assert.match(board, /const TOOL_CLASS = 'h-8 w-8 rounded-none p-0/);
    assert.doesNotMatch(board, /\/>(Journal|Dossier|Passage)\n/);
    // Chaque icône de relecture porte son nom pour les lecteurs d'écran.
    const tools = [...board.matchAll(/:class="TOOL_CLASS"[^>]*>/g)].map((m) => m[0]);
    assert.ok(tools.length >= 3);
    for (const tool of tools) assert.match(tool, /aria-label="[^"]+"/);
    assert.doesNotMatch(board, /compactActions|flex-col items-end/);
    assert.doesNotMatch(board, /<Eye /);
});

test('l’état chez ce service porte l’icône de son bloc, et son libellé reste écrit', () => {
    const row = (state, extra = {}) => ({ module: { state, ...extra } });
    assert.equal(stateIcon(row('NONE')), Clock);
    assert.equal(stateIcon(row('REQUESTED')), Clock);
    assert.equal(stateIcon(row('IN_PROGRESS')), Activity);
    assert.equal(stateIcon(row('IN_PROGRESS', { is_waiting_on_results: true })), Hourglass);
    assert.equal(stateIcon(row('COMPLETED')), CircleCheck);
    // Chaque pastille d'état a son icône, à côté du libellé — jamais l'icône seule.
    const badges = board.match(/<component :is="stateIcon\(row\)" class="h-3 w-3" aria-hidden="true" \/>\{\{ row\.module\.state_label \}\}/g) ?? [];
    assert.equal(badges.length, 3);
});

test('par où entrer : un mot sous l’état, avec l’icône du service attendu', () => {
    assert.deepEqual(entryPathHint({ pathway: null }), null);
    assert.deepEqual(entryPathHint({}), null);
    const care = entryPathHint({ pathway: { code: 'CARE_FIRST' } });
    assert.equal(care.label, 'Soins d’abord');
    assert.equal(care.icon, Bandage);
    const medicine = entryPathHint({ pathway: { code: 'MEDICINE_ONLY' } });
    assert.equal(medicine.label, 'Attendu en Médecine');
    assert.equal(medicine.icon, Stethoscope);
    assert.match(board, /v-if="entryPathHint\(row\)"/);
});

test('prendre à contre-sens passe par la fenêtre du parcours, avant la file', () => {
    // Le bouton demande d'abord le parcours ; la file (ADR-121) ne vient qu'après.
    assert.match(board, /const requestTakeCharge = \(row\) => \{\s+if \(row\.pathway\)/);
    assert.doesNotMatch(board, /@click="skipGuard\.request\(row\)"/);
    // Consulter quand même rejoint le garde-fou de la file ; faire les soins suit l'adresse du serveur.
    assert.match(board, /const consultAnyway = [\s\S]*?skipGuard\.request\(row\)/);
    assert.match(board, /postTakeCharge\(row, row\.pathway\?\.care_take_charge_url\)/);
    // Attendu ailleurs : un bouton verrouillé qui informe, sans adresse de prise en charge.
    assert.match(board, /v-else-if="row\.pathway\?\.blocking"[\s\S]*?<Lock /);
    assert.match(board, /<EntryPathConfirm/);
});

test('la fenêtre du parcours : la Médecine décide, les Soins sont seulement informés', () => {
    // Titre, message et raisons viennent du serveur.
    assert.match(entryPath, /pathway\?\.title/);
    assert.match(entryPath, /pathway\.message/);
    assert.match(entryPath, /v-for="\(reason, index\) in pathway\.reasons"/);
    // Attendu en Médecine : un seul bouton, qui ferme.
    assert.match(entryPath, /<template v-if="blocking">\s*<Button[^>]*>Compris<\/Button>/);
    // Attendu aux Soins : les deux décisions, et « faire les soins » seulement avec son adresse.
    assert.match(entryPath, /v-if="pathway\?\.care_take_charge_url"/);
    assert.match(entryPath, />Faire les soins moi-même/);
    assert.match(entryPath, />Consulter quand même/);
    // Aucune règle de parcours n'est écrite à l'écran.
    assert.doesNotMatch(entryPath, /CARE_ONLY|MEDICINE_DIRECT|CARE_THEN_MEDICINE/);
});

test('un patient attendu ailleurs ne tient pas de place dans la file : le garde-fou ne compte que PENDING', () => {
    assert.match(skipGuard, /row\.status === 'PENDING'/);
});

test('une ligne ne porte rien de clinique ni de financier', () => {
    assert.doesNotMatch(board, /total_amount|unit_price|diagnos|vital|allerg/i);
});

test('l’état chez ce service a sa teinte, le libellé vient du serveur', () => {
    assert.equal(stateTone({ module: { state: 'NONE' } }), 'neutral');
    assert.equal(stateTone({ module: { state: 'REQUESTED' } }), 'warning');
    assert.equal(stateTone({ module: { state: 'IN_PROGRESS', is_waiting_on_results: false } }), 'info');
    assert.equal(stateTone({ module: { state: 'IN_PROGRESS', is_waiting_on_results: true } }), 'warning');
    assert.equal(stateTone({ module: { state: 'COMPLETED' } }), 'success');
    assert.match(board, /\{\{ row\.module\.state_label \}\}/);
});

test('le besoin se résume sans montant, et son absence se dit', () => {
    const row = { needs: [
        { description: 'Consultation', quantity: '1.00' },
        { description: 'ECG', quantity: '2.00' },
        { description: 'Pansement', quantity: '1.00' },
    ] };

    assert.equal(needSummary(row), 'Consultation · ECG ×2 · +1');
    assert.equal(needSummary({ needs: [] }), null);
    assert.deepEqual(visibleNeeds(row).items.map((need) => need.description), ['Consultation', 'ECG']);
    assert.equal(visibleNeeds(row).more, 1);
    // Chaque besoin a l'icône de son service ; un besoin inconnu a la sienne.
    assert.match(board, /<component :is="needIcon\(need\.module\)"/);
    assert.match(board, /<CircleHelp class="h-4 w-4 shrink-0" aria-hidden="true" \/>Besoin à préciser/);
});

test('un patient en attente attend depuis son arrivée — l’ordre même de la file', () => {
    const now = Date.parse('2026-09-23T10:00:00Z');
    const requested = { module: { state: 'REQUESTED', oriented_at: '2026-09-23T08:30:00Z' }, episode: { started_at: '2026-09-23T08:00:00Z' } };
    const free = { module: { state: 'NONE' }, episode: { started_at: '2026-09-23T09:45:00Z' } };
    const taken = { module: { state: 'IN_PROGRESS', accepted_at: '2026-09-23T07:00:00Z' }, episode: { started_at: '2026-09-23T06:00:00Z' } };

    assert.equal(elapsedFrom(requested), '2026-09-23T08:00:00Z');
    assert.equal(elapsedFrom(free), '2026-09-23T09:45:00Z');
    assert.match(waitTone(requested, now), /red/);
    assert.equal(waitTone(free, now), 'text-muted-foreground');
    // Un patient pris en charge n'attend plus : jamais de rouge d'attente.
    assert.equal(waitTone(taken, now), 'text-muted-foreground');
});

test('un besoin prend l’icône de son service', () => {
    assert.equal(needIcon('CARE'), Bandage);
    assert.equal(needIcon('MEDICINE'), Stethoscope);
    assert.equal(needIcon('IMAGING'), ScanLine);
    assert.equal(needIcon('RECEPTION'), ClipboardList);
});

test('où est le patient ailleurs, en une ligne', () => {
    assert.equal(elsewhereLabel({ label: 'Médecine', state_label: 'orienté, en attente', by: null, queue_number: 3 }), 'Médecine · orienté, en attente · n° 3');
    assert.equal(elsewhereLabel({ label: 'Soins', state_label: 'pris en charge', by: 'Hery', queue_number: null }), 'Soins · pris en charge · Hery');
});

test('« En attente » et une recherche vide ne s’écrivent pas dans l’adresse', () => {
    assert.deepEqual(boardParams('waiting', ''), {});
    assert.deepEqual(boardParams('suggested', 'Rakoto'), { view: 'suggested', q: 'Rakoto' });
});

test('chaque vue vide a son message, adapté au service', () => {
    assert.equal(emptyState('CARE', 'in_progress').title, 'Aucun passage pris en charge aux Soins');
    assert.equal(emptyState('MEDICINE', 'waiting').title, 'Aucun patient en attente');
});
