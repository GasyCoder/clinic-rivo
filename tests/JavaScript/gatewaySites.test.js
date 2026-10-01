import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { centralLogoOf, identityOf, siteLogoOf, statusOf } from '../../resources/js/utilities/gatewaySites.js';

/** ADR-184 (amendement du 2026-10-02) — les logos de la passerelle, lus sur chaque déploiement. */
test('rien n’est deviné pendant le chargement', () => {
    assert.equal(identityOf(undefined, 'A'), null);
    assert.equal(statusOf(null), null);
    assert.equal(siteLogoOf(null), null);
});

test('un site absent de la réponse est dit indisponible', () => {
    const identity = identityOf({ central: null, clinics: {} }, 'B');
    assert.equal(statusOf(identity).label, 'Indisponible');
    assert.equal(statusOf({ status: 'maintenance' }).label, 'En maintenance');
    assert.equal(statusOf({ status: 'online' }).tone, 'success');
});

test('le logo d’un site, puis son icône, et une image cassée cède la place', () => {
    const identity = { status: 'online', logo_url: 'https://a/logo', icon_url: 'https://a/icon' };
    assert.equal(siteLogoOf(identity), 'https://a/logo');
    assert.equal(siteLogoOf(identity, new Set(['https://a/logo'])), 'https://a/icon');
    assert.equal(siteLogoOf(identity, new Set(['https://a/logo', 'https://a/icon'])), null);
});

test('le logo central est celui du portail, sinon celui de la passerelle', () => {
    assert.equal(centralLogoOf({ central: { logo_url: 'https://p/logo' } }, '/own.png'), 'https://p/logo');
    assert.equal(centralLogoOf({ central: null }, '/own.png'), '/own.png');
    assert.equal(centralLogoOf({ central: { logo_url: 'https://p/logo' } }, null, new Set(['https://p/logo'])), null);
});

test('la page est écrite en shadcn, sans DashWind', () => {
    const page = fs.readFileSync('resources/js/Pages/SiteSelect.vue', 'utf8');
    assert.doesNotMatch(page, /Components\/UI\//);
    assert.doesNotMatch(page, /\bni ni-/);
    assert.match(page, /@\/Components\/Shadcn\/Checkbox\.vue/);
    // Le site habituel vient du poste : jamais lu pendant le rendu serveur.
    assert.match(page, /isMounted\.value\s*\n?\s*\? sites\.value\.find/);
});
