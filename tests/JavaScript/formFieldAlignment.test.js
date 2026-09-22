import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const field = fs.readFileSync('resources/js/Components/Shadcn/FormField.vue', 'utf8');
const create = fs.readFileSync('resources/js/Pages/Reception/Create.vue', 'utf8');

/**
 * L'alignement des champs sur une rangée.
 *
 * Les champs font tous `h-11` : ce n'était pas eux le problème. C'est la
 * **ligne de libellé** qui divergeait — celle de « Naissance ou âge » porte
 * le sélecteur Date/Âge et faisait une dizaine de pixels de plus, poussant
 * son champ plus bas que « Sexe » et « Téléphone » de la même rangée.
 */

test('la ligne de libellé a une hauteur fixe', () => {
    assert.match(field, /class="mb-1\.5 flex h-6 items-center justify-between gap-3"/);
});

/** La commande du libellé vit dans cette ligne, sans la faire grandir. */
test('le sélecteur Date/Âge occupe le slot du libellé', () => {
    assert.match(field, /<slot name="action" \/>/);

    const birth = create.slice(create.indexOf(":label=\"isExternalNewborn ? 'Naissance du bébé' : 'Naissance ou âge'\""));
    const block = birth.slice(0, birth.indexOf('</FormField>'));

    assert.match(block, /<template #action>/);
    // Assez petit pour tenir dans la ligne : c'est ce qui garde l'alignement.
    assert.match(block, /px-2 py-0\.5 text-\[10px\]/);
});

/**
 * Un `<label>` enveloppant deux boutons radio cocherait le premier au
 * moindre clic dans la zone : ces champs-là sont des `div`.
 */
test('un contrôle composé n’est jamais enveloppé d’un label', () => {
    assert.match(field, /as: \{ type: String, default: 'label' \}/);

    for (const label of ['Sexe', 'Pièce d’identité']) {
        const at = create.indexOf(`label="${label}"`);
        assert.notEqual(at, -1, `${label} introuvable`);

        const opening = create.slice(create.lastIndexOf('<FormField', at), at);
        assert.match(opening, /as="div"/, `${label} doit être un div`);
    }

    assert.match(create, /role="radiogroup" aria-label="Sexe"/);
});

/** Un seul idiome de libellé dans le fichier : c'est ce qui a dérivé. */
test('plus aucun libellé écrit à la main ne subsiste', () => {
    assert.doesNotMatch(create, /mb-1\.5 block text-sm font-medium text-foreground/);
});

/**
 * Vue condense l'espace entre deux éléments d'un template. Un espace écrit
 * dans le texte — `> {{ hint }}` — disparaît donc au rendu, et « Pièce
 * d'identité » se collait à « (facultatif) ». Les espacements passent par
 * une marge, qui ne peut pas être avalée.
 */
test('les espacements du libellé sont posés en marge, pas dans le texte', () => {
    assert.match(field, /class="ms-1 font-normal text-muted-foreground">\{\{ hint \}\}</);
    assert.match(field, /class="ms-0\.5 text-destructive">\*</);

    assert.doesNotMatch(field, /> \{\{ hint \}\}/);
    assert.doesNotMatch(field, /"text-destructive"> \*/);
});

/**
 * `Select` impose `min-w-[176px]` — utile pour un filtre isolé, mais il
 * débordait d'une colonne de grille plus étroite et chevauchait le champ
 * voisin. Les colonnes concernées sont élargies **et** la contrainte levée.
 */
test('un Select en colonne étroite ne déborde plus', () => {
    // Ancré sur le marqueur du template : le nom du champ apparaît aussi
    // dans le script, et y découper donnerait une tranche vide.
    for (const marker of [
        ':model-value="patientForm.civility"',
        'v-model="patientForm.identity_document_type"',
    ]) {
        const at = create.indexOf(marker);
        assert.notEqual(at, -1, `${marker} introuvable`);

        const tag = create.slice(create.lastIndexOf('<Select', at), create.indexOf('/>', at));
        assert.match(tag, /min-w-0/, `${marker} doit pouvoir rétrécir`);
    }

    // Les colonnes tiennent la largeur par défaut du Select.
    assert.doesNotMatch(create, /grid-cols-\[1[0-7][0-9]px_minmax\(0,1fr\)/);
});
