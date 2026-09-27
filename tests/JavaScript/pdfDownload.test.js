import test from 'node:test';
import assert from 'node:assert/strict';
import { pdfFileName, printAsPdf } from '../../resources/js/utilities/pdfDownload.js';

/**
 * Le PDF d'un document est celui que le navigateur propose à l'impression :
 * le seul contrôle qu'on ait sur le fichier est son nom, qui suit le titre de
 * la page le temps de l'impression (ADR-070, ADR-118).
 */
test('le nom du fichier assemble les morceaux connus, séparés par « - »', () => {
    assert.equal(
        pdfFileName('Journaux de traitement', 'A-26-0001', 'BEZARA Florent'),
        'Journaux de traitement - A-26-0001 - BEZARA Florent',
    );
});

test('un morceau absent ne laisse ni séparateur ni espace de trop', () => {
    assert.equal(pdfFileName('Journaux de traitement', null, undefined, '  '), 'Journaux de traitement');
    assert.equal(pdfFileName(), '');
});

test('les caractères qu’un nom de fichier ne peut pas porter sont remplacés', () => {
    const name = pdfFileName('Dossier / A-26:0001', 'Rakoto*Jean?', '"x"<y>|z\\w');

    for (const forbidden of ['/', ':', '*', '?', '"', '<', '>', '|', '\\']) {
        assert.ok(!name.includes(forbidden), `« ${forbidden} » est resté dans ${name}`);
    }
    assert.match(name, /^Dossier A-26 0001 - Rakoto Jean - /);
});

test('les espaces multiples sont réduits et le nom ne dépasse pas 120 caractères', () => {
    assert.equal(pdfFileName('Journaux   de     traitement'), 'Journaux de traitement');
    assert.ok(pdfFileName('x'.repeat(400)).length <= 120);
});

/** Un faux navigateur : on vérifie l'ordre exact des gestes, sans rien imprimer. */
const fakeBrowser = (title = 'Clinique Saint Georges') => {
    const listeners = new Map();
    const calls = [];
    const doc = { title };
    const win = {
        addEventListener: (type, handler) => listeners.set(type, handler),
        removeEventListener: (type, handler) => {
            if (listeners.get(type) === handler) listeners.delete(type);
        },
        print: () => calls.push(`print avec le titre « ${doc.title} »`),
    };

    return { doc, win, listeners, calls };
};

test('la fenêtre d’impression s’ouvre sous le titre du fichier', () => {
    const { doc, win, calls } = fakeBrowser();

    printAsPdf('Journaux de traitement - A-26-0001', { win, doc });

    assert.deepEqual(calls, ['print avec le titre « Journaux de traitement - A-26-0001 »']);
});

test('le titre de la page est rendu à la fin de l’impression, pas avant', () => {
    const { doc, win, listeners } = fakeBrowser('Dossier patient');

    printAsPdf('Journaux de traitement', { win, doc });
    assert.equal(doc.title, 'Journaux de traitement', 'le titre doit rester le temps de l’impression');

    listeners.get('afterprint')();

    assert.equal(doc.title, 'Dossier patient');
    assert.equal(listeners.has('afterprint'), false, 'l’écouteur ne doit pas survivre à l’impression');
});

test('deux impressions successives ne se piègent pas sur le titre du fichier précédent', () => {
    const { doc, win, listeners } = fakeBrowser('Dossier patient');

    printAsPdf('Premier', { win, doc });
    listeners.get('afterprint')();
    printAsPdf('Second', { win, doc });
    listeners.get('afterprint')();

    assert.equal(doc.title, 'Dossier patient');
});
