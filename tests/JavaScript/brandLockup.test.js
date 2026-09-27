import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const lockup = fs.readFileSync('resources/js/Components/Layout/BrandLockup.vue', 'utf8');
const sidebar = fs.readFileSync('resources/js/Components/Layout/Sidebar.vue', 'utf8');
const header = fs.readFileSync('resources/js/Components/Layout/Header.vue', 'utf8');
const brand = fs.readFileSync('resources/js/lib/brand.js', 'utf8');
const authMark = fs.readFileSync('resources/js/Components/Auth/BrandMark.vue', 'utf8');

/**
 * Le bandeau latéral portait l'enseigne en casse normale, la barre du haut —
 * seul endroit où elle apparaît sur un téléphone — en 16 px gras. Deux rendus
 * pour la même clinique, qui divergeaient à chaque retouche.
 */
test('la marque n’est écrite qu’à un seul endroit', () => {
    for (const [name, source] of [['le bandeau latéral', sidebar], ['la barre du haut', header]]) {
        assert.match(source, /<BrandLockup/, `${name} n’utilise pas le composant`);
        assert.doesNotMatch(source, /\{\{ site\.brand \}\}/, `${name} réécrit l’enseigne`);
    }
});

/** La demande : l'enseigne en majuscules, dans une écriture nette. */
test('l’enseigne est en majuscules', () => {
    assert.match(lockup, /text-\[11px\] font-bold uppercase leading-none tracking-\[0\.06em\] text-foreground/);
    assert.match(lockup, /text-\[9px\] font-bold uppercase leading-none tracking-\[0\.16em\] text-muted-foreground/);
});

/**
 * Seules les graisses 400 et 700 de Nunito sont embarquées
 * (`resources/dashwind/assets/css/custom/font-faces.css`) : `font-extrabold`
 * retomberait sur la 700 sans rien changer à l'écran, en laissant croire le
 * contraire à la lecture du code.
 */
test('aucune graisse qui n’existe pas dans la police', () => {
    const faces = fs.readFileSync('resources/dashwind/assets/css/custom/font-faces.css', 'utf8');
    assert.doesNotMatch(faces, /font-weight: 800/);
    assert.doesNotMatch(lockup, /font-extrabold|font-black/);
});

/**
 * Le nom vient de `rivo.brand`, propre à chaque déploiement : un sigle écrit
 * en dur mentirait dès qu'un site change d'enseigne.
 */
test('les initiales sont dérivées du nom configuré', () => {
    assert.match(brand, /export const monogramOf/);
    assert.match(brand, /\.slice\(0, 3\)/);
    assert.match(lockup, /monogramOf\(brand\.value\)/);

    // La page de connexion doit afficher les mêmes lettres que la navigation.
    assert.match(authMark, /import \{ monogramOf \} from '@\/lib\/brand'/);
    assert.doesNotMatch(authMark, /\.map\(\(word\) => word\[0\]\)/);
});

/**
 * « Clinique Saint Georges » tient dans les 170 px que laissent le bouton de
 * repli et la pastille ; le nom d'un site plus long doit se tronquer au lieu
 * de pousser la pastille hors de la barre.
 */
test('un nom trop long se tronque au lieu de déborder', () => {
    assert.match(lockup, /class="flex min-w-0 items-center gap-2\.5"/);
    assert.match(lockup, /<span class="flex min-w-0 flex-col">/);
    assert.match(lockup, /h-8 w-8 shrink-0/);
    assert.match(lockup, /truncate font-heading/);

    // Un conteneur qui ne rétrécit pas empêcherait `truncate` d'opérer.
    assert.doesNotMatch(sidebar, /flex flex-shrink-0 min-w-0/);
});

/** Repliée, la barre latérale n'a la place que du bouton : la marque s'efface. */
test('la marque s’efface avec la barre repliée', () => {
    assert.match(sidebar, /group-\[&\.is-compact:not\(\.has-hover\)\]\/sidebar:opacity-0/);
});
