import { createInertiaApp, router } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { applySiteSettings, siteBrand } from '@/lib/siteSettings';
import { installPageLoading } from '@/composables/usePageLoading';

const pinia = createPinia();

// ADR-184 — le nom de l'application et l'écriture de l'Ariary viennent des
// paramètres du site : posés à l'ouverture, puis à chaque visite.
router.on('navigate', (event) => applySiteSettings(event.detail.page?.props?.site));

// Pendant un changement de page, un squelette à la forme de la page qui arrive.
installPageLoading(router);

createInertiaApp({
    title: (title) => (title ? `${title} - ${siteBrand()}` : siteBrand()),

    withApp(app, { page }) {
        applySiteSettings(page?.props?.site);
        app.use(pinia);
    },
});
