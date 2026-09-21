import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/**
 * ADR-160 — le patient hospitalisé descend au bloc et garde son lit.
 * Le build ne voit pas ces promesses d'écran ; ce test les garde.
 */
const stay = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');
const surgeryShow = fs.readFileSync('resources/js/Pages/Surgery/Show.vue', 'utf8');
const surgeryIndex = fs.readFileSync('resources/js/Pages/Surgery/Index.vue', 'utf8');
const banner = fs.readFileSync('resources/js/Components/Surgery/HospitalStayBanner.vue', 'utf8');

test('le séjour transfère au bloc par sa propre route, jamais par une consultation', () => {
    assert.match(stay, /\/hospitalisation\/\$\{props\.stay\.uuid\}\/bloc/);
    assert.match(stay, /capabilities\.can_request_surgery/);
    assert.match(stay, /garde son lit/);
});

test('le transfert est une signature : non fermable au clic extérieur, responsable nommé', () => {
    assert.match(stay, /title="Transférer au bloc opératoire"[\s\S]*?:dismissible="false"/);
    assert.match(stay, /\$page\.props\.auth\.user\.name/);
    // L'intervention est choisie, jamais devinée : pas d'envoi sans elle.
    assert.match(stay, /:disabled="surgeryForm\.processing \|\| !selectedProcedure"/);
});

test('le bloc et l’anesthésie savent qu’un lit attend le patient', () => {
    assert.match(surgeryShow, /<HospitalStayBanner v-if="hospitalStay"/);
    assert.match(banner, /remonte à son lit/);
    assert.match(banner, /v-if="stay\.url"/, 'le lien vers le séjour n’est servi qu’avec le droit');
    assert.match(surgeryIndex, /request\.hospital_stay/);
    assert.match(surgeryIndex, /HOSPITALIZATION: BedDouble/);
});

test('la nouvelle carte et son bandeau sont en shadcn (ADR-099)', () => {
    for (const file of [banner]) {
        assert.doesNotMatch(file, /Components\/UI\/Icon\.vue|<Icon\b|\bnk-|ni ni-/);
        assert.match(file, /lucide-vue-next/);
    }
});
