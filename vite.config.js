import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import inertia from '@inertiajs/vite';
import { writeFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';

// Les fichiers de public/build/assets portent l'empreinte de leur contenu dans leur
// nom : un nouveau build change le nom, jamais le contenu d'un fichier existant.
// Le navigateur peut donc les garder un an sans jamais redemander au serveur s'ils
// ont changé. Sans cet en-tête, l'hébergeur (o2switch) n'envoyait aucun
// Cache-Control, et chaque page rechargée revalidait ses ~70 fichiers JS/CSS —
// autant d'allers-retours de ~200 ms depuis Madagascar.
// Le fichier est réécrit à chaque build : Vite vide public/build avant d'écrire.
const immutableAssets = () => ({
    name: 'rivo:immutable-assets',
    apply: 'build',
    writeBundle(options) {
        writeFileSync(`${options.dir}/assets/.htaccess`, [
            '<IfModule mod_headers.c>',
            '    Header set Cache-Control "public, max-age=31536000, immutable"',
            '</IfModule>',
            '<IfModule mod_expires.c>',
            '    ExpiresActive On',
            '    ExpiresDefault "access plus 1 year"',
            '</IfModule>',
            '',
        ].join('\n'));
    },
});

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),

        vue(),

        inertia(),

        immutableAssets(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(
                new URL('./resources/js', import.meta.url)
            ),
        },
    },

    server: {
        watch: {
            // Laravel écrit sans arrêt dans ces dossiers pendant qu'on travaille :
            // les DevTools Inertia y déposent un JSON par requête
            // (storage/inertia-devtools), SQLite y crée et supprime ses journaux,
            // les logs et les caches y tournent. Chaque *création* de fichier dans
            // l'arbre surveillé fait recharger entièrement toutes les pages ouvertes
            // (Vite suppose que le nouveau fichier peut résoudre un import cassé) :
            // le rechargement déclenche une requête, qui écrit un nouveau fichier, qui
            // déclenche un rechargement. Aucun de ces dossiers n'appartient au
            // graphe de modules du frontend.
            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/bootstrap/cache/**',
                '**/public/build/**',
                '**/.agents/**',
                '**/.claude/**',
                '**/.codex/**',
                '**/template/**',
            ],
        },
    },
});
