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
            ignored: [
                '**/storage/framework/views/**',
                '**/.agents/**',
                '**/.claude/**',
                '**/.codex/**',
                '**/template/**',
            ],
        },
    },
});
