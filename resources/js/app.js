import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => {
        return title
            ? `${title} - Clinique Saint Georges`
            : 'Clinique Saint Georges';
    },
});
