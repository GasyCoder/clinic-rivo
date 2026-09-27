import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/*
 * La fiche d'un employé (ADR-066, ADR-194) : écrite en shadcn, avec « écrire »
 * et le badge professionnel. Ce que le build ne vérifie pas.
 */
const read = (path) => fs.readFileSync(`resources/js/${path}`, 'utf8');
const SHOW = 'Pages/Administration/Employees/Show.vue';
const BADGE = 'Components/Administration/EmployeeBadge.vue';

test('la fiche employé est en shadcn, sans reliquat DashWind', () => {
    const show = read(SHOW);
    assert.doesNotMatch(show, /Components\/UI\/(Icon|Button|Input)\.vue/);
    assert.doesNotMatch(show, /\bni ni-|\bnk-|\b(?:bg|text|border)-(?:gray|slate)-\d|<select\b|<textarea\b/);
    assert.match(show, /from '@\/Components\/Shadcn\/Card\.vue'/);
    assert.match(show, /from 'lucide-vue-next'/);
});

test('aucun composant de la fiche ni du badge n’est utilisé sans être importé', () => {
    const BUILTINS = new Set(['Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense', 'Component', 'Head', 'Link']);
    const missing = [];
    for (const file of [SHOW, BADGE]) {
        const source = read(file);
        const cut = source.indexOf('<template>');
        const script = source.slice(0, cut);
        for (const [, name] of source.slice(cut).matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)) {
            if (!BUILTINS.has(name) && !new RegExp(`\\b${name}\\b`).test(script)) missing.push(`${file} → <${name}>`);
        }
    }
    assert.deepEqual([...new Set(missing)], [], `composant utilisé sans import :\n${missing.join('\n')}`);
    // Head et Link viennent d'Inertia : ils doivent tout de même être importés.
    assert.match(read(SHOW), /import \{ Head, Link, router, useForm, usePage \} from '@inertiajs\/vue3'/);
});

test('« écrire » ouvre la messagerie RIVO déjà adressée, sinon la messagerie du poste', () => {
    const show = read(SHOW);
    assert.match(show, /composeHref\(props\.employee\.name, props\.employee\.email\)/);
    assert.match(show, /`mailto:\$\{props\.employee\.email\}`/);
    assert.match(show, /<Mail class="h-4 w-4" \/>/);
    // Sans adresse, le bouton reste visible, désactivé, et dit pourquoi.
    assert.match(show, /Aucune adresse email : elle se crée avec son accès/);

    const webmail = read('Pages/Webmail/Index.vue');
    assert.match(webmail, /composeTarget\(new URL\(page\.url, window\.location\.origin\)\.search\)/);
    assert.match(webmail, /openCompose\('new', \{ to: target \}\)/);
});

test('le badge : format carte, QR du seul matricule, impression réservée à un dossier en poste', () => {
    const badge = read(BADGE);
    assert.match(badge, /width: 54mm; height: 85\.6mm;/);
    assert.match(badge, /QRCode\.toDataURL\(String\(number\)/);
    assert.match(badge, /watch\(\(\) => props\.employee\.employee_number/);
    assert.match(badge, /print-color-adjust: exact/);

    const show = read(SHOW);
    assert.match(show, /<Button type="button" variant="outline" @click="badgeOpen = true"><IdCard class="h-4 w-4" \/>Badge<\/Button>/);
    assert.match(show, /const canPrintBadge = computed\(\(\) => can\('employees\.print'\) && !badgeInactive\.value\)/);
    assert.match(show, /<div v-if="printingBadge" id="employee-badge-sheet" class="hidden print:block">/);
});
