import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Quitter une page ne jette jamais un brouillon en silence (ADR-178).
 *
 * Deux sorties à garder : fermer ou recharger l'onglet — le navigateur
 * demande lui-même, c'est la seule question qu'il accepte de poser — et
 * suivre un lien de l'application, où la page pose sa propre question avant
 * de laisser partir la visite.
 *
 * Seules les visites GET sont retenues : un enregistrement (PUT, POST…) est
 * précisément ce qui vide le brouillon, il ne doit jamais demander « quitter
 * sans enregistrer ? ».
 *
 * @param {import('vue').Ref<boolean>} dirty
 */
export function useUnsavedChangesGuard(dirty) {
    /** La visite retenue, en attente d'une réponse. */
    const pendingVisit = ref(null);
    let bypass = false;
    let removeBefore = null;

    const onBeforeUnload = (event) => {
        if (! dirty.value) return;

        event.preventDefault();
        event.returnValue = '';
    };

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);

        removeBefore = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (bypass || ! dirty.value || String(visit.method).toLowerCase() !== 'get') return;

            pendingVisit.value = visit;
            event.preventDefault();
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeBefore?.();
    });

    const leave = () => {
        const visit = pendingVisit.value;

        pendingVisit.value = null;

        if (! visit) return;

        bypass = true;
        router.visit(visit.url, { preserveScroll: visit.preserveScroll, onFinish: () => { bypass = false; } });
    };

    const stay = () => { pendingVisit.value = null; };

    return { pendingVisit, leave, stay };
}
