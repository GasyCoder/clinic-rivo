import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const queue = fs.readFileSync('resources/js/Pages/Maternity/Index.vue', 'utf8');

/**
 * Les commentaires décrivent souvent ce qui a été retiré : les garder ferait
 * échouer une assertion sur le motif qu'elle interdit, précisément parce
 * qu'il est expliqué.
 */
const markup = queue.replace(/<!--[\s\S]*?-->/g, '');

/** ADR-099 : la file Maternité tient sur la couche partagée. */
test('la file Maternité utilise les primitives shadcn', () => {
    for (const component of ['Badge', 'Button', 'Card', 'IconInput']) {
        assert.ok(queue.includes(`@/Components/Shadcn/${component}.vue`), `${component} n’est pas utilisé`);
    }

    // Le Card DashWind partagé n’est plus la carte de cet écran.
    assert.doesNotMatch(queue, /@\/Components\/UI\/Card\.vue/);
    // Variantes et tailles d’avant la migration.
    assert.doesNotMatch(queue, /variant="white-outline"|size="rg"/);
    // Pastilles écrites à la main.
    assert.doesNotMatch(queue, /rounded bg-red-600 px-2/);
});

/**
 * `<Link as="button">` enveloppant un `<Button>` produisait un bouton dans
 * un bouton : HTML invalide, et le clic intérieur ne déclenchait pas
 * toujours la navigation. La prise en charge est un POST, écrit comme tel.
 */
test('la prise en charge est un POST, pas un bouton dans un bouton', () => {
    assert.doesNotMatch(markup, /<Link[^>]*as="button"[\s\S]{0,120}<Button/);
    assert.match(queue, /router\.post\(`\/maternity\/orientations\/\$\{orientation\.uuid\}\/accept`/);
    // Un double clic n’ouvre pas deux prises en charge.
    assert.match(queue, /if \(accepting\.value\) return;/);
});

/**
 * Artefact d’une substitution de palette : `dark:hover:bg-muted0/30` n’est
 * pas une classe. Elle ne fait échouer aucun build — elle ne se voit qu’à
 * l’écran, par une absence de survol.
 */
test('aucune classe cassée par une substitution', () => {
    assert.doesNotMatch(queue, /(muted|primary|card|border|foreground)\d/);
});

/** Le compte vient du serveur : recalculé sur la page, il mentirait dès la deuxième. */
test('les compteurs restent ceux du serveur', () => {
    assert.match(queue, /count: props\.counts\.waiting/);
    assert.match(queue, /count: props\.counts\.active/);
    assert.match(queue, /count: props\.counts\.completed/);
    assert.doesNotMatch(queue, /count:[^,\n]*\.data\b/);
});

/** Une page vide dit quoi faire, pas seulement qu’elle est vide — et chaque vue a son propre message. */
test('la vue vide oriente, vue par vue', () => {
    assert.match(queue, /Aucune patiente à prendre en charge/);
    assert.match(queue, /Aucune patiente chez le médecin/);
    assert.match(queue, /Aucune prise en charge terminée/);
    assert.match(queue, /const empty = computed\(\(\) => EMPTY\[props\.filter\]/);
    assert.match(queue, /mx-auto grid h-11 w-11 place-items-center/);
});

/**
 * ADR-135 : quatre vues exclusives suivent le parcours d’une patiente. Elles
 * doivent venir du serveur, et « Orientées vers Médecine » ne doit jamais
 * être déduit d’un libellé.
 */
test('les quatre vues de la file suivent le parcours de la patiente', () => {
    for (const value of ['waiting', 'active', 'doctor', 'completed']) {
        assert.match(queue, new RegExp(`value: '${value}'`), `la vue ${value} manque`);
    }

    assert.match(queue, /count: props\.counts\.doctor/);
    assert.match(queue, /count: props\.counts\.waiting/);
    assert.match(queue, /lg:grid-cols-4/);
});

test('chaque ligne dit ce qui suit la Maternité, sans le deviner', () => {
    // Les deux suites viennent de vraies orientations / demandes servies par le serveur.
    assert.match(queue, /orientation\.follow_up\?\.medicine/);
    assert.match(queue, /orientation\.follow_up\?\.cesarean/);
    assert.match(queue, /follow_up\.medicine\.label/);
    assert.match(queue, /follow_up\.cesarean\.label/);
});

test('l’en-tête est celui, partagé, du module Soins', () => {
    assert.match(queue, /@\/Components\/Care\/SoinsWorkspaceHeader\.vue/);
    assert.match(queue, /<SoinsWorkspaceHeader/);
    assert.doesNotMatch(markup, /<header class="flex flex-col gap-4 lg:flex-row/);
});
