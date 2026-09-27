import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { DELIVERY_STEPS, PRENATAL_STEPS, stepFilled } from '../../resources/js/utilities/maternityWorkflow.js';
import { withIcons } from '../../resources/js/utilities/maternityFieldIcons.js';
import { maternityShowPage } from './support/maternityPage.js';

const page = maternityShowPage();
const panel = fs.readFileSync('resources/js/Components/Maternity/MaternityPrescriptionPanel.vue', 'utf8');
const prescriptions = fs.readFileSync('resources/js/Components/Hospitalization/StayPrescriptions.vue', 'utf8');
const fields = fs.readFileSync('app/Support/Maternity/MaternityEncounterFields.php', 'utf8');
const step = (name) => fs.readFileSync(`resources/js/Components/Maternity/Steps/${name}.vue`, 'utf8');

/** ADR-205 — la sage-femme prescrit, comme le médecin : l'ordonnance a son étape dans les deux parcours. */
test('l’ordonnance a son étape dans les deux parcours', () => {
    const keys = (steps) => steps.map((item) => item.key);
    assert.deepEqual(keys(PRENATAL_STEPS).slice(3, 6), ['paraclinical', 'prescription', 'summary']);
    assert.deepEqual(keys(DELIVERY_STEPS).slice(-3), ['newborn', 'prescription', 'transmission']);
    assert.equal(PRENATAL_STEPS.find((item) => item.key === 'prescription').hash, 'ordonnance');

    // Renseignée seulement par une ordonnance réelle, jamais par une saisie en cours.
    assert.equal(stepFilled('prescription', {}, { prescriptionCount: 1 }), true);
    assert.equal(stepFilled('prescription', {}, { prescriptionCount: 0 }), false);
    assert.match(page, /prescriptionCount: \(props\.prescriptions \?\? \[\]\)\.filter\(\(prescription\) => prescription\.status !== 'CANCELLED'\)\.length/);
    assert.match(page, /prescription: Pill,/);
    assert.equal((page.match(/<MaternityPrescriptionPanel/g) ?? []).length, 2, 'l’étape est rendue dans les deux parcours');
});

test('le même écran d’ordonnance que le séjour, adressé à la Maternité', () => {
    assert.match(panel, /import StayPrescriptions from '@\/Components\/Hospitalization\/StayPrescriptions\.vue'/);
    assert.match(panel, /:base-url="`\/maternity\/orientations\/\$\{orientationUuid\}`"/);
    assert.match(panel, /context="maternity"/);
    // La délivrance suit la règle ordinaire : la patiente règle d'abord (ADR-049).
    assert.match(prescriptions, /la patiente règle à la Caisse, puis la Pharmacie délivre/);
    // Sans le droit, l'étape dit ce qui manque plutôt que de se taire (ADR-154, ADR-158).
    assert.match(panel, /« prescriptions\.create »/);
    assert.match(panel, /Le profil sage-femme les recommande/);
});

test('chaque choix structuré servi par le serveur a son icône', () => {
    const constant = (name) => [...fields.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`))[1].matchAll(/'([A-Z_]+)' =>/g)].map((match) => match[1]);
    const fieldsByKey = {
        visit_reasons: 'VISIT_REASONS',
        reported_since_last: 'REPORTED_SINCE_LAST',
        fetal_movements: 'FETAL_MOVEMENTS',
        contractions: 'CONTRACTIONS',
    };

    for (const [field, name] of Object.entries(fieldsByKey)) {
        const values = constant(name);
        assert.ok(values.length > 0, name);
        for (const option of withIcons(field, values.map((value) => ({ value, label: value })))) {
            assert.ok(option.icon, `${field}.${option.value} sans icône`);
        }
    }
    // Une valeur inconnue passe sans icône, sans rien casser.
    assert.deepEqual(withIcons('visit_reasons', [{ value: 'NOUVEAU', label: 'Nouveau' }]), [{ value: 'NOUVEAU', label: 'Nouveau' }]);
});

/** Les étapes prénatales se lisent par blocs iconés (`ClinicalSubsection`), comme l'Anesthésie. */
test('les étapes prénatales sont rangées en blocs iconés', () => {
    for (const name of ['PrenatalInterviewStep', 'PrenatalExaminationStep', 'PrenatalSummaryStep']) {
        const source = step(name);
        assert.match(source, /import ClinicalSubsection from '@\/Components\/Clinical\/ClinicalSubsection\.vue'/, name);
        assert.match(source, /<ClinicalSubsection :icon="/, name);
        assert.doesNotMatch(source, /Components\/UI\/(Icon|Card|Button)/, name);
    }
    const link = fs.readFileSync('resources/js/Components/Maternity/PregnancyLinkSection.vue', 'utf8');
    assert.match(link, /<ClinicalSubsection/);
    // L'interrogatoire coche des cartes : l'état se lit, la case reste pour le clavier.
    assert.match(step('PrenatalInterviewStep'), /role="checkbox"/);
    assert.match(step('PrenatalInterviewStep'), /:aria-checked="reported\(\)\.includes\(option\.value\)"/);
});

test('la feuille d’une sage-femme porte son titre, et un lien vers une étape la suit', () => {
    const print = fs.readFileSync('resources/js/Pages/Medicine/PrescriptionPrint.vue', 'utf8');
    assert.match(print, /prescription\.prescriber_title \? `\$\{props\.prescription\.prescriber_title\} \$\{name\}` : doctorName\(name\)/);
    assert.match(print, /'Signature et cachet du prescripteur'/);
    assert.doesNotMatch(print, /<strong>Dr \{\{/, 'plus de « Dr » écrit en dur');
    // `#ordonnance` ne recharge pas la page : l'étape suit le changement d'ancre.
    assert.match(page, /window\.addEventListener\('hashchange', followHash\)/);
    assert.match(page, /onBeforeUnmount\(\(\) => window\.removeEventListener\('hashchange', followHash\)\)/);
});
