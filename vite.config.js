import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import vue from '@vitejs/plugin-vue';
import inertia from '@inertiajs/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),

        vue(),

        inertia(),
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
