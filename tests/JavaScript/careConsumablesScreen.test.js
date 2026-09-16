import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const FILES = {
    'CareConsumables/Index.vue': fs.readFileSync('resources/js/Pages/Pharmacy/CareConsumables/Index.vue', 'utf8'),
    'Partials/CareConsumableQueue.vue': fs.readFileSync('resources/js/Pages/Pharmacy/Partials/CareConsumableQueue.vue', 'utf8'),
};

/** ADR-099 : shadcn-vue est le design system, DashWind un reliquat. */
test('l’écran n’importe plus de composant DashWind', () => {
    for (const [name, source] of Object.entries(FILES)) {
        for (const dashwind of ['UI/Icon.vue', 'UI/Button.vue', 'UI/Badge.vue', 'UI/Input.vue', 'UI/Card.vue']) {
            assert.ok(! source.includes(dashwind), `${name} importe encore ${dashwind}`);
        }

        assert.doesNotMatch(source, /<Icon[ />]/, `${name} rend encore la police d’icônes`);
    }
});

/**
 * Une couleur codée en dur ne suit pas le thème. L'ambre reste : c'est la
 * teinte propre à ce circuit — un consommable déjà posé sur le patient —
 * et elle porte ses deux variantes de thème.
 */
test('l’écran s’appuie sur les tokens sémantiques', () => {
    for (const [name, source] of Object.entries(FILES)) {
        for (const legacy of ['text-slate-400', 'text-slate-500', 'text-slate-700', 'border-gray-200', 'bg-gray-950', 'bg-white ']) {
            assert.ok(! source.includes(legacy), `${name} porte encore « ${legacy} »`);
        }
    }
});

/**
 * Les compteurs viennent du serveur. Recalculés depuis la liste affichée,
 * ils mentiraient dès qu'elle est filtrée par la recherche — et c'est
 * précisément quand la file est longue qu'on les regarde.
 */
test('les compteurs de la file lisent le résumé du serveur', () => {
    const index = FILES['CareConsumables/Index.vue'];

    assert.match(index, /QueueCounters/);
    assert.match(index, /count: \(summary\.value\.pending \?\? 0\) \+ \(summary\.value\.partially_served \?\? 0\)/);
    assert.match(index, /count: summary\.value\.lines_to_serve \?\? 0/);

    // « Unités à sortir » est un indicateur, pas un filtre : aucune section
    // de l'écran ne liste des unités isolées de leur demande.
    assert.match(index, /value: '__lines__'[\s\S]*?filterable: false/);
});

/**
 * La fenêtre de sortie de stock passe par la primitive partagée — piège de
 * focus compris — et refuse de se fermer sur un clic à côté : on y saisit
 * des quantités, pas un texte qu'on relit.
 */
test('la sortie de stock se confirme dans la fenêtre partagée, non fermable au clic', () => {
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    assert.match(queue, /import Dialog from '@\/Components\/Shadcn\/Dialog\.vue'/);
    assert.doesNotMatch(queue, /class="fixed inset-0/);
    assert.match(queue, /:dismissible="false"/);
});

/** Le contrat serveur ne bouge pas : même route, mêmes quantités. */
test('la confirmation poste toujours les mêmes lignes à la même route', () => {
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    assert.match(queue, /`\/pharmacy\/care-consumables\/\$\{serveTarget\.value\.uuid\}\/serve`/);
    assert.match(queue, /\.filter\(\(line\) => line\.quantity > 0\)/);
    assert.match(queue, /capabilities\.can_serve_care_consumables/);
});

/**
 * Une quantité réduite se voit **avant** le clic : servir 2 compresses sur
 * 5 déclarées laisse un reliquat, et c'est au moment de confirmer que le
 * pharmacien doit le savoir, pas en relisant l'historique.
 */
test('un service partiel est annoncé avant la confirmation', () => {
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    assert.match(queue, /servePartial = computed\(\(\) => serveLines\.value\.some\(/);
    assert.match(queue, /Le reliquat restera à servir sur cette demande/);
});

/**
 * Le propriétaire a signalé que l'écran laissait croire que ce matériel
 * était gratuit. Il est facturé depuis l'origine (ADR-072), mais rien à
 * l'écran ne le disait — et un échec de facturation restait invisible.
 */
test('l’écran dit ce que le patient doit, et où il paie', () => {
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    assert.match(queue, /Porté au compte du patient/);
    assert.match(queue, /formatMoney\(request\.billing\.total_amount\)/);
    assert.match(queue, /invoice\.status_label/);

    // La Pharmacie n'encaisse rien (ADR-013) : elle lit le statut, elle ne
    // propose aucun paiement et ne mène à aucune caisse.
    assert.doesNotMatch(queue, /payments\./);
    assert.doesNotMatch(queue, /href="\/cash/);
});

test('une ligne jamais facturée est nommée, pas tue', () => {
    const index = FILES['CareConsumables/Index.vue'];
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    // Un compteur, donc visible sans chercher.
    assert.match(index, /value: 'unbilled'[\s\S]*?count: summary\.value\.unbilled_lines \?\? 0/);

    // Et une section qui nomme la ligne, la raison et qui doit la réparer.
    assert.match(queue, /tab === 'unbilled'/);
    assert.match(queue, /line\.billing\?\.reason/);
    assert.match(queue, /à régulariser à la Réception/i);
});

/** ADR-036 : le prix n'apparaît que côté Pharmacie, jamais au poste de soins. */
test('le montant est lu, jamais saisi', () => {
    const queue = FILES['Partials/CareConsumableQueue.vue'];

    // Le seul champ saisissable de l'écran reste la quantité sortie du stock.
    const inputs = queue.match(/<Input\b/g) ?? [];
    assert.equal(inputs.length, 1);
    assert.match(queue, /:id="`serve-\$\{line\.uuid\}`"/);
});
