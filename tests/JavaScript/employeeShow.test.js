import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/*
 * La fiche d'un employé (ADR-066, ADR-194) : écrite en shadcn, avec « écrire »
 * et le badge du personnel (ADR-209). Ce que le build ne vérifie pas.
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

test('le badge : le modèle de la clinique, seul badge de la fiche (ADR-209)', () => {
    const badge = read(BADGE);
    // Un seul modèle pour tout le personnel, dessiné en SVG, réglé par site : plus de carte à QR (ADR-198).
    assert.match(badge, /person: \{ type: Object, required: true \}/);
    assert.match(badge, /design: \{ type: Object, default: \(\) => \(\{\}\) \}/);
    assert.doesNotMatch(badge, /QRCode/);

    const show = read(SHOW);
    assert.match(show, /<EmployeeBadgeCard v-if="badge" :employee-uuid="employee\.uuid" :badge="badge" :can-print="can\('employees\.print'\)" \/>/);
    // Le bouton de l'en-tête mène à la page d'impression du badge, jamais à une seconde carte.
    assert.match(show, /<Button v-if="badge && can\('employees\.print'\)" :as="Link" :href="hrUrl\(`\/administration\/employees\/\$\{employee\.uuid\}\/badge`\)" variant="outline"><IdCard class="h-4 w-4" \/>Badge<\/Button>/);
    assert.doesNotMatch(show, /badgeOpen|printBadge|employee-badge-sheet|import EmployeeBadge from/);
});
