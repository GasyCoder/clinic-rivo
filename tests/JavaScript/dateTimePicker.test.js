import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const picker = fs.readFileSync('resources/js/Components/Shadcn/DateTimePicker.vue', 'utf8');
const calendar = fs.readFileSync('resources/js/Components/Shadcn/Calendar.vue', 'utf8');
const scheduler = fs.readFileSync('resources/js/Components/Surgery/SurgeonScheduler.vue', 'utf8');

test('le sélecteur échange la même valeur qu’un champ datetime-local', () => {
    assert.match(picker, /PATTERN = \/\^\(\\d\{4\}-\\d\{2\}-\\d\{2\}\)T\(\\d\{2\}\):\(\\d\{2\}\)\//);
    assert.match(picker, /emit\('update:modelValue', `\$\{draftDate\.value\}T\$\{pad\(draftHour\.value\)\}:\$\{pad\(draftMinute\.value\)\}`\)/);
});

test('aucune heure n’est inventée : un jour seul ne produit pas de valeur', () => {
    assert.match(picker, /const emitIfComplete = \(\) => \{\s+if \(complete\.value\)/);
    assert.match(picker, /draftHour\.value !== null && draftMinute\.value !== null/);
    assert.match(picker, /choisissez l’heure/);
});

test('panneau compact : heure et minutes sur une ligne sous le calendrier, sans second portail', () => {
    assert.match(picker, /<NativeSelect :model-value="draftHour" :options="hourOptions" placeholder="--" aria-label="Heure"/);
    assert.match(picker, /<NativeSelect :model-value="draftMinute" :options="minuteOptions" placeholder="--" aria-label="Minutes"/);
    assert.doesNotMatch(picker, /role="listbox"/);
    assert.doesNotMatch(picker, /Shadcn\/Select\.vue/);
    // Cases de 28 px : le panneau garde à peu près la largeur du champ.
    assert.match(calendar, /inline-flex h-7 w-8 items-center/);
});

test('une seule flèche par liste : celle du plugin forms est retirée', () => {
    const nativeSelect = fs.readFileSync('resources/js/Components/Shadcn/NativeSelect.vue', 'utf8');
    assert.match(nativeSelect, /appearance-none[^']*bg-none/);
    assert.match(nativeSelect, /<ChevronDown/);
});

test('« Maintenant » lit l’heure au clic, jamais au rendu, et le jour sans heure se signale', () => {
    assert.match(picker, /const pickNow = \(\) => \{\s+const now = new Date\(\);/);
    assert.match(picker, /awaitingTime = computed\(\(\) => draftDate\.value !== null && draftHour\.value === null\)/);
});

test('le calendrier est en français, la semaine commence le lundi', () => {
    assert.match(calendar, /locale="fr-FR"/);
    assert.match(calendar, /:week-starts-on="1"/);
    assert.match(calendar, /from 'reka-ui'/);
});

test('la programmation utilise le sélecteur, plus le champ natif', () => {
    assert.match(scheduler, /<DateTimePicker id="scheduled_at" v-model="form\.scheduled_at"/);
    assert.doesNotMatch(scheduler, /type="datetime-local"/);
});

const datePicker = fs.readFileSync('resources/js/Components/Shadcn/DatePicker.vue', 'utf8');
const field = fs.readFileSync('resources/js/utilities/datePickerField.js', 'utf8');

test('la date seule échange « AAAA-MM-JJ » et ferme le panneau au choix du jour', () => {
    assert.match(datePicker, /emit\('update:modelValue', date\.toString\(\)\);\s+open\.value = false;/);
    assert.match(field, /\/\^\(\\d\{4\}-\\d\{2\}-\\d\{2\}\)\//);
});

test('les deux sélecteurs respectent min / max, name, lecture seule et taille', () => {
    for (const source of [datePicker, picker]) {
        assert.match(source, /:min-value="minValue" :max-value="maxValue" caption="dropdown"/);
        assert.match(source, /<input v-if="name" type="hidden" :name="name"/);
        assert.match(source, /readonly: \{ type: Boolean/);
        assert.match(source, /triggerClass\(\{ size, invalid, readonly/);
    }
});

test('le calendrier propose Mois et Année en listes natives, sans second portail', () => {
    assert.match(calendar, /aria-label="Mois"/);
    assert.match(calendar, /aria-label="Année"/);
    assert.match(calendar, /v-model:placeholder="displayed"/);
});

test('plus aucun champ date natif dans l’application : tout passe par les composants', () => {
    const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = `${dir}/${entry.name}`;

        return entry.isDirectory() ? walk(path) : (path.endsWith('.vue') ? [path] : []);
    });
    const offenders = walk('resources/js').filter((path) => /type="(date|datetime-local)"/.test(fs.readFileSync(path, 'utf8')));

    assert.deepEqual(offenders, []);
});
