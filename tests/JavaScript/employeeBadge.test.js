import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { Baby, GraduationCap, Hospital, IdCard, ShieldCheck, Stethoscope, Syringe } from 'lucide-vue-next';
import {
    BADGE_CHOICES, BADGE_DEFAULT_DESIGN, BADGE_FIELDS, BADGE_GEOMETRY, BADGE_NUMBERS, BADGE_SAMPLES, BADGE_SIZE_MM,
    BADGE_SWITCHES, BADGE_TEXT_LIMITS, badgeCardMm, badgeDesignFromSettings, badgeFooter, badgeFormValue, badgeIcon,
    badgeNameLines, badgeNameRows, badgeNumber, badgePalette, badgeRole, badgeSeal, badgeSheetLayout, badgeSheetPath,
    chunk, fitFontSize, fitLine, internValidity, mixHex, sealText,
} from '../../resources/js/utilities/employeeBadge.js';

const read = (path) => fs.readFileSync(path, 'utf8');

/**
 * ADR-209 — l'icône du badge se lit sur le code stable du référentiel (fonction,
 * puis département, puis filière), jamais sur un libellé qui se renomme.
 */
test('the badge icon follows the reference codes, never a label', () => {
    assert.equal(badgeIcon({ job_title_code: 'DOCTOR', department_code: 'SUPPORT' }), Stethoscope, 'la fonction passe avant le département');
    assert.equal(badgeIcon({ job_title_code: 'GUARD' }), ShieldCheck);
    assert.equal(badgeIcon({ department_code: 'MATERNITY' }), Baby);
    assert.equal(badgeIcon({ is_intern: true, internship: { field_code: 'NURSING' } }), Syringe);
    assert.equal(badgeIcon({ is_intern: true, internship: { field_code: 'INCONNU' } }), GraduationCap);
    assert.equal(badgeIcon({ job_title: 'Médecin', department: 'Médecine' }), IdCard, 'un libellé seul ne choisit rien');
});

test('an intern badge says intern, and the name and number are read from the file', () => {
    assert.deepEqual(badgeRole({ department: 'Médecine', job_title: 'Médecin' }), { pill: 'Médecine', line: 'Médecin' });
    assert.deepEqual(badgeRole({ job_title: 'Gardien' }), { pill: 'Gardien', line: '' }, 'sans département, la fonction va dans la pastille');
    assert.deepEqual(badgeRole({ is_intern: true, department: 'Maternité', internship: { field: 'Sage-femme' } }), { pill: 'Stagiaire', line: 'Sage-femme' });

    assert.deepEqual(badgeNameLines({ first_name: 'Hanitra', last_name: 'Rakotoarisoa' }), { first: 'Hanitra', last: 'RAKOTOARISOA' });
    assert.deepEqual(badgeNameLines({ first_name: null, last_name: 'Rabe' }), { first: '', last: 'RABE' });

    assert.equal(badgeNumber({ badge_number: 'B-17', employee_number: 'EMP-1' }), 'B-17');
    assert.equal(badgeNumber({ badge_number: null, employee_number: 'EMP-1' }), 'EMP-1');

    assert.equal(internValidity({ is_intern: true, internship: { ends_on: '2026-12-31' } }), 'Valable jusqu’au 31/12/2026');
    assert.equal(internValidity({ is_intern: false, internship: { ends_on: '2026-12-31' } }), '');
});

test('a long text shrinks to fit its line, never below its minimum', () => {
    const short = fitFontSize('RABE', { max: 40, min: 17, width: 410, ratio: 0.7 });
    const long = fitFontSize('ANDRIANASOLONIANARIVELO', { max: 40, min: 17, width: 410, ratio: 0.7 });
    const absurd = fitFontSize('X'.repeat(200), { max: 40, min: 17, width: 410, ratio: 0.7 });

    assert.equal(short, 40);
    assert.ok(long < 40 && long > 17);
    assert.equal(absurd, 17);
});

test('the palette comes from the two colors, and an invalid color falls back to the clinic', () => {
    assert.equal(mixHex('#000000', '#FFFFFF', 0.5), '#808080');
    const palette = badgePalette({ primary: '#0a5c36', accent: 'jaune' });
    assert.equal(palette.primary, '#0A5C36');
    assert.equal(palette.accent, BADGE_DEFAULT_DESIGN.accent);
    assert.notEqual(palette.ink, palette.primary);
});

test('the seal text is written like the server does it', () => {
    assert.deepEqual(sealText('Clinique Saint Georges'), { top: 'CLINIQUE', bottom: 'SAINT GEORGES' });
    assert.deepEqual(sealText('Rivo'), { top: 'RIVO', bottom: '' });

    const server = read('app/Support/Hr/BadgeDesign.php');
    assert.match(server, /public static function sealText\(string \$brand\)/);
    assert.match(server, /DEFAULT_PRIMARY = '#1B4FA3'/);
    assert.match(server, /DEFAULT_ACCENT = '#F6C318'/);
    assert.ok(server.includes(`DEFAULT_TAGLINE = '${BADGE_DEFAULT_DESIGN.tagline}'`), 'la devise par défaut est la même des deux côtés');
    assert.ok(server.includes(`DEFAULT_EMBLEM_URL = '${BADGE_DEFAULT_DESIGN.emblem_url}'`));
    assert.ok(fs.existsSync(`public${BADGE_DEFAULT_DESIGN.emblem_url}`), 'l’emblème par défaut est livré');
});

test('the sheet lives under its list: the checked files, otherwise the filters of every page', () => {
    assert.equal(badgeSheetPath({ status: 'active', q: 'Rabe' }), '/administration/employees/badges?status=active&q=Rabe');
    assert.equal(badgeSheetPath({ scope: 'interns', status: 'current', field: 'f-1' }), '/administration/internships/badges?status=current&field=f-1');
    assert.equal(badgeSheetPath({ uuids: ['a', 'b'], status: 'active' }), '/administration/employees/badges?uuids%5B%5D=a&uuids%5B%5D=b');
    assert.equal(badgeSheetPath(), '/administration/employees/badges');

    const routes = read('routes/hr.php');
    assert.ok(routes.indexOf("'/employees/badges'") < routes.indexOf("Route::get('/employees/{employee}'"), 'la planche passe avant /employees/{employee}');
    assert.match(routes, /'\/internships\/badges'.*->defaults\('scope', 'interns'\)/);
});

test('the badge is one SVG with the card ratio, in portrait or landscape, printed at its real size', () => {
    assert.deepEqual({ ...BADGE_SIZE_MM }, { width: 54, height: 85.6 });
    assert.deepEqual([BADGE_GEOMETRY.PORTRAIT.width, BADGE_GEOMETRY.PORTRAIT.height], [540, 856], 'le rapport de la carte : 54 × 85,6');
    assert.deepEqual([BADGE_GEOMETRY.LANDSCAPE.width, BADGE_GEOMETRY.LANDSCAPE.height], [856, 540], 'le même rapport, couché');

    const badge = read('resources/js/Components/Administration/EmployeeBadge.vue');
    assert.match(badge, /:viewBox="`0 0 \$\{g\.width\} \$\{g\.height\}`"/, 'une seule dessin, deux dispositions');
    assert.match(badge, /@fontsource\/dancing-script/, 'la devise manuscrite est livrée avec l’application, jamais un CDN');
    assert.doesNotMatch(badge, /Components\/UI\/Icon\.vue|nk-|ni ni-/, 'shadcn et lucide seulement (ADR-099)');

    const page = read('resources/js/Pages/Administration/Employees/Badges.vue');
    assert.match(page, /@page \{ size: \$\{sheet\.value\.page\.width\}mm \$\{sheet\.value\.page\.height\}mm; margin: 0; \}/, 'la page suit le papier choisi');
    assert.match(page, /gridTemplateColumns: `repeat\(\$\{Math\.max\(1, sheet\.value\.columns\)\}, \$\{sheet\.value\.card\.width\}mm\)`/, 'les cartes au millimètre');
    assert.match(page, /zoom: 1 !important/, 'jamais réduite à l’impression');
    assert.match(page, /print-color-adjust: exact/, 'les couleurs s’impriment');
});

test('every layout keeps each element inside the card', () => {
    for (const [name, g] of Object.entries(BADGE_GEOMETRY)) {
        const inside = (x, y, label) => assert.ok(x >= 0 && y >= 0 && x <= g.width && y <= g.height, `${name} : ${label} sort de la carte (${x}, ${y})`);
        const box = (b, label) => { inside(b.x, b.y, label); inside(b.x + b.width, b.y + b.height, label); };

        box(g.name.band, 'le bandeau du nom');
        box(g.name.main, 'la ligne du nom');
        box(g.logo, 'le logo');
        inside(g.pill.cx - g.pill.maxWidth / 2, g.pill.cy - g.pill.height / 2, 'la pastille');
        inside(g.pill.cx + g.pill.maxWidth / 2, g.pill.cy + g.pill.height / 2, 'la pastille');
        inside(g.seal.cx - 99 * g.seal.scale, g.seal.cy - 99 * g.seal.scale, 'le sceau');
        inside(g.seal.cx + 99 * g.seal.scale, g.seal.cy + 99 * g.seal.scale, 'le sceau');
        inside(g.photo.cx - g.photo.r * 1.25, g.photo.cy - g.photo.r * 1.25, 'la photo et ses anneaux');
        inside(g.photo.cx + g.photo.r * 1.25, g.photo.cy + g.photo.r * 1.25, 'la photo et ses anneaux');
        inside(g.medallion.cx + g.medallion.r, g.medallion.cy + g.medallion.r, 'le médaillon');
        inside(g.wave.cx + g.wave.fit / 2, g.wave.y, 'le pied');
        assert.ok(g.name.band.y + g.name.band.height > g.name.main.y, `${name} : le bandeau chevauche la ligne du nom, comme sur le modèle`);
    }
});

test('the badge settings are the same list on both sides, with the same defaults', () => {
    const server = read('app/Support/Hr/BadgeDesign.php');
    const list = (name) => server.match(new RegExp(`public const ${name} = \\[([\\s\\S]*?)\\n    \\];`))[1];
    const quoted = (text) => [...text.matchAll(/'([A-Za-z0-9_]+)'/g)].map((match) => match[1]);

    assert.deepEqual(quoted(list('FIELDS')), [...BADGE_FIELDS]);
    assert.deepEqual(quoted(server.match(/public const COLORS = \[([^\]]*)\]/)[1]), BADGE_FIELDS.filter((field) => field.endsWith('_color')));

    const choices = list('CHOICES');
    for (const [field, values] of Object.entries(BADGE_CHOICES)) {
        const line = choices.match(new RegExp(`'${field}' => \\[([^\\]]*)\\]`))[1];
        assert.deepEqual(quoted(line), values, `${field} : mêmes choix, même valeur de la clinique en premier`);
    }
    for (const [field, bounds] of Object.entries(BADGE_NUMBERS)) {
        assert.ok(list('NUMBERS').includes(`'${field}' => [${bounds.join(', ')}]`), `${field} : mêmes bornes`);
    }
    for (const [field, value] of Object.entries(BADGE_SWITCHES)) {
        assert.ok(list('SWITCHES').includes(`'${field}' => ${value}`), `${field} : même valeur par défaut`);
    }
    for (const [field, max] of Object.entries(BADGE_TEXT_LIMITS)) {
        assert.ok(list('TEXTS').includes(`'${field}' => ${max}`), `${field} : même longueur`);
    }
    for (const field of [...BADGE_FIELDS]) {
        assert.ok(read('database/migrations/2026_11_07_090000_add_badge_design_to_app_settings.php').includes(`'${field}'`)
            || read('database/migrations/2026_11_08_090000_add_badge_layout_to_app_settings.php').includes(`'${field}'`), `${field} a sa colonne`);
    }
});

test('a setting never set shows the clinic value, and the same value never counts as changed', () => {
    assert.equal(badgeFormValue('badge_paper', null), 'A4');
    assert.equal(badgeFormValue('badge_paper', 'NEON'), 'A4');
    assert.equal(badgeFormValue('badge_orientation', 'LANDSCAPE'), 'LANDSCAPE');
    assert.equal(badgeFormValue('badge_name_size', null), 100);
    assert.equal(badgeFormValue('badge_name_size', '130'), 130);
    assert.equal(badgeFormValue('badge_name_size', 999), 150, 'borné');
    assert.equal(badgeFormValue('badge_page_margin', ''), 8);
    assert.equal(badgeFormValue('badge_show_site', null), false, 'le site ne s’affiche pas d’emblée');
    assert.equal(badgeFormValue('badge_show_photo', null), true);
    assert.equal(badgeFormValue('badge_show_photo', 0), false);
    assert.equal(badgeFormValue('badge_text_color', '#0a5c36'), '#0A5C36');
    assert.equal(badgeFormValue('badge_footer_text', null), '');
});

test('the sheet counts the cards that fit on the page, like the server', () => {
    const a4 = badgeSheetLayout({ paper: 'A4' });
    assert.deepEqual([a4.columns, a4.rows, a4.perPage], [3, 3, 9]);
    assert.deepEqual(a4.page, { width: 210, height: 297 });

    const a4Landscape = badgeSheetLayout({ paper: 'A4', paperOrientation: 'LANDSCAPE' });
    assert.deepEqual([a4Landscape.columns, a4Landscape.rows], [4, 2]);

    const lyingCards = badgeSheetLayout({ paper: 'A4', orientation: 'LANDSCAPE' });
    assert.deepEqual([lyingCards.columns, lyingCards.rows], [2, 4], 'une carte couchée se range autrement');

    const card = badgeSheetLayout({ paper: 'CARD', cardSize: 'LARGE' });
    assert.equal(card.mode, 'card');
    assert.deepEqual(card.page, { width: 62.1, height: 98.4 }, 'une carte par page : la page prend la taille de la carte');

    const tooBig = badgeSheetLayout({ paper: 'A5', margin: 25, cardSize: 'XLARGE', orientation: 'LANDSCAPE' });
    assert.equal(tooBig.fits, false);
    assert.equal(tooBig.perPage, 0);

    assert.deepEqual(badgeCardMm('XLARGE', 'PORTRAIT'), { width: 70.2, height: 111.3 });
    assert.deepEqual(chunk([1, 2, 3, 4, 5], 2), [[1, 2], [3, 4], [5]]);

    const server = read('app/Support/Hr/BadgeDesign.php');
    assert.match(server, /'STANDARD' => 1\.0, 'LARGE' => 1\.15, 'XLARGE' => 1\.3/, 'les mêmes tailles de carte');
    assert.match(server, /'A4' => \[210, 297\]/);
});

test('names, role and footer follow what the site shows', () => {
    const person = { first_name: 'Hanitra', last_name: 'Rakotoarisoa', department: 'Médecine', job_title: 'Médecin', employee_number: 'EMP-1' };

    assert.deepEqual(badgeNameRows(person), { band: 'Hanitra', main: 'RAKOTOARISOA' });
    assert.deepEqual(badgeNameRows(person, { name_order: 'LAST_FIRST' }), { band: 'RAKOTOARISOA', main: 'Hanitra' });
    assert.deepEqual(badgeNameRows(person, { name_case: 'AS_IS' }), { band: 'Hanitra', main: 'Rakotoarisoa' });
    assert.deepEqual(badgeNameRows({ last_name: 'Rabe' }, { name_order: 'LAST_FIRST' }), { band: '', main: 'RABE' }, 'une seule partie : pas de bandeau');

    assert.deepEqual(badgeRole(person, { show_department: false }), { pill: 'Médecin', line: '' }, 'sans service, la fonction monte dans la pastille');
    assert.deepEqual(badgeRole(person, { show_job: false }), { pill: 'Médecine', line: '' });
    assert.deepEqual(badgeRole(person, { show_department: false, show_job: false }), { pill: '', line: '' });
    assert.deepEqual(
        badgeRole({ is_intern: true, internship: { field: 'Sage-femme' } }, { show_department: false, intern_label: 'Élève' }),
        { pill: 'Élève', line: 'Sage-femme' },
        'un stagiaire garde toujours sa mention',
    );

    assert.equal(badgeFooter(person), 'N° EMP-1');
    assert.equal(badgeFooter(person, { number_label: 'Réf.', footer_text: 'Accès réservé' }), 'Réf. EMP-1  ·  Accès réservé');
    assert.equal(badgeFooter(person, { show_number: false }), '');
    assert.equal(badgeFooter({ is_intern: true, internship: { ends_on: '2026-12-31' } }, { show_validity: false }), '');

    assert.equal(badgeIcon({ job_title_code: 'GUARD' }, { icon: 'HOSPITAL' }), Hospital, 'une icône fixe pour tout le monde');
    assert.equal(badgeIcon({ job_title_code: 'GUARD' }, { icon: 'AUTO' }), ShieldCheck);
});

test('a line never overflows its box, whatever size is chosen', () => {
    const big = fitLine('RABE', { max: 40, min: 17, width: 410, ratio: 0.7, percent: 150, cap: 50 });
    assert.equal(big.size, 50, 'agrandi, mais jamais plus haut que sa case');
    assert.equal(big.length, null);

    const small = fitLine('RABE', { max: 40, min: 17, width: 410, ratio: 0.7, percent: 70 });
    assert.equal(small.size, 28);

    const long = fitLine('X'.repeat(80), { max: 40, min: 17, width: 410, ratio: 0.7 });
    assert.equal(long.size, 17);
    assert.equal(long.length, 410, 'trop long à la taille minimale : resserré à la largeur exacte');
});

test('the settings preview builds the same design as the server would', () => {
    const design = badgeDesignFromSettings({
        badge_primary_color: '#0a5c36', badge_text_color: 'rouge', badge_orientation: 'LANDSCAPE', badge_paper: 'A5',
        badge_page_margin: '', badge_show_site: true, badge_seal_top: 'Rivo', badge_seal_bottom: '',
    }, { brand: 'Clinique Saint Georges', site: 'Ambondromamy' });

    assert.equal(design.primary, '#0A5C36');
    assert.equal(design.text, null, 'une couleur invalide n’est jamais retenue');
    assert.equal(design.orientation, 'LANDSCAPE');
    assert.deepEqual(design.print, { paper: 'A5', orientation: 'PORTRAIT', margin: 8, gap: 4, cut_marks: true });
    assert.equal(design.show_site, true);
    assert.equal(design.site, 'Ambondromamy');
    assert.deepEqual(design.seal, { top: 'RIVO', bottom: '' }, 'une ligne réglée : le sceau n’écrit que ce qui est réglé');
    assert.deepEqual(badgeSeal('Clinique Saint Georges', '', ''), sealText('Clinique Saint Georges'));

    assert.match(read('app/Support/Hr/BadgeDesign.php'), /public static function seal\(string \$brand, \?string \$top, \?string \$bottom\)/);
    assert.equal(badgePalette({ primary: '#1B4FA3', text: '#112233' }).ink, '#112233');
    assert.equal(badgePalette({ primary: '#1B4FA3', background: '#fafafa' }).paper, '#FAFAFA');
});

test('the lists, the file and the settings lead to the badge', () => {
    const employees = read('resources/js/Pages/Administration/Employees/Index.vue');
    assert.match(employees, /can\('employees\.print'\)/);
    assert.match(employees, /badgeSheetPath\(\{ uuids: selected\.value \}\)/, 'les dossiers cochés');
    assert.match(employees, /statusFilter\.value !== 'archived'/, 'un dossier archivé n’a plus de badge');

    const internships = read('resources/js/Pages/Administration/Internships/Index.vue');
    assert.match(internships, /scope: 'interns'/);

    assert.match(read('resources/js/Pages/Administration/Employees/Show.vue'), /<EmployeeBadgeCard v-if="badge"/);

    const settings = read('resources/js/Components/Settings/BadgeSettings.vue');
    assert.match(settings, /kind="badge"/, 'l’emblème se dépose comme le logo');
    assert.match(settings, /<EmployeeBadge :person="person" :design="design" \/>/, 'l’aperçu est le composant même qui s’imprime');
    assert.match(settings, /badgeDesignFromSettings\(props\.form/, 'l’aperçu suit le formulaire avant d’enregistrer');
    for (const tab of ['apparence', 'textes', 'elements', 'polices', 'disposition']) {
        assert.match(settings, new RegExp(`<TabsContent value="${tab}"`), `l’onglet « ${tab} »`);
    }
    assert.equal(BADGE_SAMPLES.intern.is_intern, true);

    const page = read('resources/js/Pages/SuperAdmin/Settings/Index.vue');
    assert.match(page, /\.\.\.BADGE_FIELDS/, 'la page des paramètres envoie tous les réglages du badge');
    assert.match(page, /badgeFormValue\(field, value\)/);
});
