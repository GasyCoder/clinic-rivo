import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/*
 * ADR-194 — la photo 4 × 4 d'un dossier RH : ce que le build ne vérifie pas.
 */
const read = (path) => fs.readFileSync(`resources/js/${path}`, 'utf8');

const FIELD = 'Components/Administration/EmployeePhotoField.vue';
// ADR-213 — la photo se règle dans la section Identité de la fiche.
const FORM = 'Components/Administration/EmployeeFile/IdentitySection.vue';

test('le champ photo garde son input monté, même sans son propre déclencheur', () => {
    const field = read(FIELD);
    // Panne constatée : l'input était dans le bloc masqué (`triggerless`), le
    // grand cadre de l'étape Identité n'avait donc plus rien à ouvrir.
    const inputAt = field.indexOf('<input ref="input" type="file"');
    const hiddenBlockAt = field.indexOf('<div v-if="!triggerless"');
    assert.ok(inputAt > 0 && hiddenBlockAt > 0);
    assert.ok(inputAt < hiddenBlockAt, 'l’input fichier doit précéder le bloc masqué, pas y vivre');

    // Le grand cadre se sert du même exemplaire : ouvrir, recadrer, retirer.
    assert.match(field, /defineExpose\(\{ open, acceptFile, recrop, removePhoto, preview, hasPhoto, canRecrop, reading \}\)/);
    const form = read(FORM);
    assert.match(form, /photoField\?\.open\(\)/);
    assert.match(form, /photoField\?\.recrop\(\)/);
    assert.match(form, /photoField\?\.removePhoto\(\)/);
});

test('le recadrage produit un carré JPEG, tourné comme à l’écran, sans jamais envoyer l’original', () => {
    const field = read(FIELD);
    assert.match(field, /canvas\.toBlob\([\s\S]*'image\/jpeg', 0\.9\)/);
    assert.match(field, /new File\(\[blob\], 'photo-4x4\.jpg', \{ type: 'image\/jpeg' \}\)/);
    // Même géométrie que l'aperçu : carré choisi, puis rotation autour du centre de l'image tournée.
    assert.match(field, /context\.setTransform\(scale, 0, 0, scale, -area\.x \* scale, -area\.y \* scale\)/);
    assert.match(field, /context\.rotate\(\(rotation\.value \* Math\.PI\) \/ 180\)/);
    // Tourner, zoomer sous le pointeur, pincer : les outils existent.
    assert.match(field, /@click="rotate\(-90\)"/);
    assert.match(field, /@click="rotate\(90\)"/);
    assert.match(field, /zoomAtPoint\(/);
    assert.match(field, /pinch = \{ \.\.\.pinchState\(\), zoom: zoom\.value \}/);
    assert.match(field, /<Slider[\s\S]*aria-label="Zoom"/);
});

test('aucun composant du dossier employé n’est utilisé sans être importé', () => {
    const BUILTINS = new Set(['Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense', 'Component']);
    const files = [
        FIELD,
        FORM,
        'Pages/Administration/Employees/Create.vue',
        'Pages/Administration/Employees/Edit.vue',
        'Components/Shadcn/Slider.vue',
        // ADR-213 — la fiche en sections et le module Banques.
        ...['EmployeeSectionCard', 'PostSection', 'ContactSection', 'MoreSection', 'PaySection', 'BankSection', 'BenefitsSection', 'BenefitCard']
            .map((name) => `Components/Administration/EmployeeFile/${name}.vue`),
        'Components/Administration/EmployeePayrollCard.vue',
        'Pages/Administration/Banks/Index.vue',
    ];
    const missing = [];

    for (const file of files) {
        const source = read(file);
        const cut = source.indexOf('<template>');
        const script = source.slice(0, cut);
        const used = new Set([...source.slice(cut).matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)].map((match) => match[1]));

        for (const name of used) {
            if (!BUILTINS.has(name) && !new RegExp(`\\b${name}\\b`).test(script)) missing.push(`${file} → <${name}>`);
        }
    }

    assert.deepEqual(missing, [], `composant utilisé sans import :\n${missing.join('\n')}`);
});

test('l’ovale du visage est à la taille d’une photo d’identité, et les conseils tiennent derrière « ! »', () => {
    const field = read(FIELD);
    // La tête occupe 70 à 80 % de la hauteur d'une photo d'identité : l'ovale suit.
    assert.match(field, /top-\[7%\] h-\[80%\] w-\[64%\][^"]*rounded-\[50%\]/);
    assert.match(field, /<NoticesButton :notices="cropNotices"/);

    const form = read(FORM);
    assert.match(form, /<NoticesButton :notices="photoNotices"/);
    for (const text of ['Visage de face, fond clair.', 'Recadrage, zoom et rotation avant l’envoi.', 'JPEG, PNG ou WebP · conservée en privé.']) {
        assert.ok(form.includes(text), `conseil manquant : ${text}`);
    }
    // Plus de liste de conseils empilée sous le cadre.
    assert.doesNotMatch(form, /<li class="flex gap-2"><Check[^>]*\/>Visage de face, fond clair<\/li>/);
});
