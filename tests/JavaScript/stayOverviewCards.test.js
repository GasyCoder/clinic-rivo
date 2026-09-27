import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/** Les cartes « Séjour » et « Demande d'hospitalisation » de la vue d'ensemble du séjour. */
const location = fs.readFileSync('resources/js/Components/Hospitalization/StayLocationCard.vue', 'utf8');
const request = fs.readFileSync('resources/js/Components/Hospitalization/StayRequestCard.vue', 'utf8');
const show = fs.readFileSync('resources/js/Pages/Hospitalization/Show.vue', 'utf8');

test('le jour de séjour n’est jamais calculé pendant le rendu serveur', () => {
    // L'heure du serveur et celle du poste ne s'accordent pas toujours : lue après le montage.
    assert.match(location, /const stayDay = ref\(null\);\s+onMounted\(\(\) => \{/);
    assert.doesNotMatch(location, /const stayDay = computed/);
});

test('le médecin est nommé par la règle commune, jamais « Dr Dr. »', () => {
    assert.match(location, /doctorName\(stay\.admitted_by\)/);
    assert.match(request, /doctorName\(request\.requested_by\)/);
});

test('la priorité se lit dans l’en-tête, l’urgence en rouge', () => {
    assert.match(request, /:variant="request\.priority === 'URGENT' \? 'destructive' : 'outline'"/);
    assert.doesNotMatch(request, /label: 'Priorité'/);
});

test('motif et diagnostic d’entrée restent dits quand ils manquent', () => {
    assert.match(request, /key: 'reason', icon: MessageSquareText, label: 'Motif', always: true/);
    assert.match(request, /key: 'admission_diagnosis', icon: Stethoscope, label: 'Diagnostic d’entrée', always: true/);
    assert.match(request, /section\.always \? 'À préciser' : 'Non renseigné'/);
});

test('« Lire la suite » n’apparaît que sous un texte qui déborde vraiment', () => {
    assert.match(request, /line-clamp-4/);
    assert.match(request, /'Replier' : 'Lire la suite'/);
    // Mesuré après montage, jamais deviné au nombre de caractères.
    assert.match(request, /element\.scrollHeight > element\.clientHeight \+ 1/);
    assert.match(request, /v-if="section\.value && \(overflowing\[section\.key\] \|\| opened\[section\.key\]\)"/);
    assert.doesNotMatch(request, /LONG_TEXT/);
    // Remesuré quand la taille du texte change — dont le chargement de la police.
    assert.match(request, /new ResizeObserver\(measure\)/);
    assert.match(request, /document\.fonts\?\.ready\?\.then\(measure\)/);
});

test('chaque rubrique se corrige seule, depuis son crayon', () => {
    // Un crayon par rubrique, et un pour la priorité ; plus de « Modifier » global.
    assert.match(request, /:aria-label="`Modifier « \$\{section\.label\} »`"[\s\S]*?@click="edit\(section\.key\)"/);
    assert.match(request, /aria-label="Modifier la priorité"[\s\S]*?@click="edit\('priority'\)"/);
    assert.doesNotMatch(request, /'Compléter' : 'Modifier'/);
    // Seule la rubrique ouverte part au serveur ; une seule à la fois.
    assert.match(request, /form\.transform\(\(data\) => \(\{ \[key\]: data\.value \}\)\)/);
    assert.match(request, /:disabled="editingKey !== null"/);
    // Échap annule, Ctrl+Entrée enregistre.
    assert.match(request, /@keydown\.esc\.prevent="cancel"/);
    assert.match(request, /@keydown\.ctrl\.enter\.prevent="save"/);
});

test('la carte « Séjour » : enregistrer fait des valeurs enregistrées le retour d’« Annuler »', () => {
    assert.match(location, /roomForm\.defaults\(\);/);
});

test('la page ne garde plus de copie de ces cartes', () => {
    assert.match(show, /<StayRequestCard[\s\S]*?:priorities="PRIORITIES"/);
    assert.doesNotMatch(show, /const requestForm = useForm/);
    assert.doesNotMatch(show, /const roomForm = useForm/);
});
