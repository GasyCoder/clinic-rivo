import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';

const pinia = createPinia();

createInertiaApp({
    title: (title) => {
        return title
            ? `${title} — RIVO`
            : 'RIVO — Clinique Saint Georges';
    },

    withApp(app) {
        app.use(pinia);
    },
});
