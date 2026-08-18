import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => {
        return title
            ? `${title} — RIVO`
            : 'RIVO — Clinique Saint Georges';
    },
});
