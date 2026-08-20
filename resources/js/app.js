import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';

const pinia = createPinia();

createInertiaApp({
    title: (title) => {
        return title
            ? `${title} - Clinique Saint Georges`
            : 'Clinique Saint Georges';
    },

    withApp(app) {
        app.use(pinia);
    },
});
