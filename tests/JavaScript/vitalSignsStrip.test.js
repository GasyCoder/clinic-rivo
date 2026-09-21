import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const strip = fs.readFileSync('resources/js/Components/Clinical/VitalSignsStrip.vue', 'utf8');

/**
 * Une ligne, pas un panneau : chaque constante tient dans une pastille d'une
 * ligne, et le décompte des anomalies ouvre lui-même le détail.
 */
test('la bande des constantes tient sur une ligne', () => {
    // Plus de tuiles à trois lignes ni de seconde rangée de tête.
    assert.doesNotMatch(strip, /min-w-40/);
    assert.doesNotMatch(strip, /h-9 w-9/);
    assert.match(strip, /whitespace-nowrap rounded-md border px-1\.5 py-1/);
    // Le bouton de décompte porte le détail : un seul geste.
    assert.match(strip, /\{\{ abnormal\.length \}\} à évaluer/);
    assert.match(strip, /:aria-controls="detailsId"/);
});

/** Jamais la couleur seule : flèche, libellé écrit et aria-label complet restent. */
test('une anomalie ne se dit jamais par la couleur seule', () => {
    assert.match(strip, /:is="vital\.arrow"/);
    assert.match(strip, /\{\{ vital\.interpretation \}\}/);
    assert.match(strip, /:aria-label="vital\.ariaLabel"/);
    assert.match(strip, /role="status" aria-live="polite"/);
});

/** Deux bandeaux sur une page ne se disputent pas le même identifiant. */
test('le détail a un identifiant unique', () => {
    assert.match(strip, /const detailsId = `vital-signs-details-\$\{useId\(\)\}`/);
    assert.doesNotMatch(strip, /id="vital-signs-details"/);
});

/** Aucun seuil n'est recalculé ici : la classification vient du serveur. */
test('aucune borne clinique n’est écrite dans la bande', () => {
    const script = strip.slice(0, strip.indexOf('<template>'));
    // Aucune comparaison à une valeur clinique (« >= 140 », « < 35 »…) :
    // la sévérité et le sens de l'écart se lisent sur le code du serveur.
    assert.doesNotMatch(script, /[<>]=?\s*(?:[3-9]\d|\d{3})\b/);
});
