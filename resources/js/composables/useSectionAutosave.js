import { computed, inject, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useAutosave } from '@/composables/useAutosave';

/**
 * ADR-221 — une section de la fiche employé qui s'enregistre toute seule.
 *
 * Elle écrit par la même route que l'ancien bouton « Enregistrer » (mêmes
 * droits, même validation, même audit) en n'envoyant que ses propres champs :
 * le serveur ne touche pas aux autres (envoi partiel, `_autosave`), et une
 * erreur dans une section n'empêche jamais les autres de s'enregistrer.
 *
 *   ready()   faux tant qu'une saisie est incomplète (un salaire sans son
 *             montant) : rien ne part, la section le dit au lieu d'afficher
 *             une erreur à chaque frappe
 *   state     idle | incomplete | dirty | saving | saved | failed
 *
 * La section s'inscrit auprès de la page (`employeeSections`), qui en tire la
 * navigation, le statut général et la garde « ne pas quitter sans enregistrer ».
 */
export function useSectionAutosave(key, initial, url, { canEdit = () => true, ready = () => true, method = 'put', delay = 1000 } = {}) {
    const form = useForm(initial);
    const send = (options) => form.transform((data) => ({ ...data, _autosave: true }))[method](url(), options);
    const autosave = useAutosave(form, send, { enabled: () => canEdit() && ready(), delay });

    const state = computed(() => {
        if (autosave.failed.value) return 'failed';
        if (autosave.saving.value) return 'saving';
        if (form.isDirty && canEdit() && ! ready()) return 'incomplete';
        if (form.isDirty) return 'dirty';
        if (autosave.savedAt.value) return 'saved';

        return 'idle';
    });

    const registry = inject('employeeSections', null);
    registry?.register(key, { state, savedAt: autosave.savedAt, flush: autosave.flush, retry: autosave.retry });
    onBeforeUnmount(() => registry?.unregister(key));

    return { form, state, ...autosave };
}
