import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const menu = fs.readFileSync('resources/js/Components/Layout/Menu.vue', 'utf8');
const admin = fs.readFileSync('resources/js/Components/Layout/AdminMenu.vue', 'utf8');
const sidebar = fs.readFileSync('resources/js/Components/Layout/Sidebar.vue', 'utf8');
const handle = fs.readFileSync('resources/js/Components/Layout/SidebarResizeHandle.vue', 'utf8');
const layout = fs.readFileSync('resources/js/Layouts/AppLayout.vue', 'utf8');

test('le menu du portail est classé en blocs repliables sans changer les destinations', () => {
    for (const heading of ['Établissements', 'Finances', 'Référentiels', 'Pharmacie & stocks', 'Organisation', 'Accès & système']) {
        assert.ok(menu.includes(`{ heading: '${heading}' }`), `bloc absent : ${heading}`);
    }

    for (const route of [
        '/super-admin/cash-registers', '/super-admin/payment-methods', '/super-admin/workspaces/finance',
        '/super-admin/workspaces/tariffs', '/super-admin/stock', '/super-admin/workspaces/hr',
        '/super-admin/workspaces/users', '/super-admin/workspaces/roles', '/super-admin/settings',
    ]) {
        assert.ok(menu.includes(`link: '${route}'`), `destination perdue : ${route}`);
    }

    assert.match(menu, /const menuData = computed\(\(\) => visibleMenu\(rawMenu\.value, can\)\)/);
    assert.match(menu, /<AdminMenu[\s\S]*?:sections="adminSections"/);
    assert.match(admin, /aria-label="Navigation Super Administration"/);
    assert.match(admin, /:aria-expanded="!compact && openSectionKey === section\.key"/);
    assert.match(admin, /openSectionKey\.value === key \? null : key/);
    assert.match(admin, /v-show="!compact && openSectionKey === section\.key"/);
});

test('le bloc et le site actifs se déplient et une seule sous-destination est active', () => {
    assert.match(admin, /watch\(activeSectionKey,[\s\S]*?\{ immediate: true \}\)/);
    assert.match(admin, /watch\(activeSiteKey,[\s\S]*?\{ immediate: true \}\)/);
    assert.match(admin, /child\.code === 'OVERVIEW'[\s\S]*?!hasModuleQuery\.value/);
    assert.match(admin, /\['HR', 'PHARMACY'\]\.includes\(child\.code\)/);
});

test('la sidebar du portail est un panneau redimensionnable accessible et mémorisé', () => {
    assert.match(sidebar, /<SidebarResizeHandle[\s\S]*?:enabled="site\?\.type === 'admin' && !compact && !mobile"/);
    assert.match(handle, /role="separator"/);
    assert.match(handle, /aria-orientation="vertical"/);
    assert.match(handle, /\['ArrowLeft', 'ArrowRight', 'Home', 'End'\]/);
    assert.match(handle, /setPointerCapture\(event\.pointerId\)/);
    assert.match(handle, /rivo\.super-admin\.sidebar-width/);
    assert.match(handle, /DOUBLE_PRESS_MS = 400/);
});

test('la page, son en-tête et la sidebar partagent la même largeur pendant le glissement', () => {
    assert.match(layout, /'--sidebar-width': `\$\{sidebarWidth\}px`/);
    assert.match(layout, /xl:ps-\[var\(--sidebar-width\)\]/);
    assert.match(layout, /xl:start-\[var\(--sidebar-width\)\]/);
    assert.match(layout, /calc\(100%-var\(--sidebar-width\)\)/);
    assert.match(layout, /is-sidebar-resizing/);
});
