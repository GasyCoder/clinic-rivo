import { onBeforeUnmount, ref, watch } from 'vue';
import { beginAutosaveVisit, endAutosaveVisit } from '@/utilities/autosaveVisits';

/**
 * Enregistre un formulaire tout seul, peu après la dernière frappe.
 *
 * Il écrit le vrai dossier par la même route que l'ancien bouton
 * « Enregistrer » : mêmes droits, même validation, même audit côté serveur.
 * Rien n'est contourné — seul le clic disparaît.
 *
 *   send(options)  lance la visite Inertia (form.put / form.post) avec les
 *                  options reçues ; c'est l'appelant qui connaît la route
 *   enabled()      faux en lecture seule : on n'écrit jamais sans le droit
 *   flush(done)    pour « Suivant » : enregistre tout de suite ce qui reste,
 *                  puis appelle `done` seulement si l'enregistrement a réussi
 *
 * `savedAt` ne passe à l'heure qu'après la réponse du serveur : l'écran ne
 * prétend jamais un enregistrement que personne n'a confirmé.
 */
export function useAutosave(form, send, { enabled = () => true, delay = 1500 } = {}) {
    const saving = ref(false);
    const savedAt = ref(null);
    const failed = ref(false);
    let timer = null;
    let queued = false;
    let lastSent = null;

    const snapshot = () => JSON.stringify(form.data());
    const hasChanges = () => form.isDirty && snapshot() !== lastSent;

    const run = (after = {}) => {
        clearTimeout(timer);
        if (form.processing) {
            queued = true;
            return;
        }

        const sent = snapshot();
        const sentData = JSON.parse(sent);
        lastSent = sent;
        saving.value = true;
        beginAutosaveVisit();

        send({
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // La référence devient ce qui a réellement été envoyé : une
                // frappe arrivée pendant la requête reste « à enregistrer ».
                form.defaults(sentData);
                failed.value = false;
                savedAt.value = new Date().toISOString();
                after.onSuccess?.(page);
            },
            onError: (errors) => {
                failed.value = true;
                lastSent = null;
                after.onError?.(errors);
            },
            onFinish: () => {
                saving.value = false;
                endAutosaveVisit();
                if (queued || (enabled() && snapshot() !== sent && !failed.value)) {
                    queued = false;
                    schedule();
                }
            },
        });
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (enabled() && hasChanges()) run();
        }, delay);
    };

    watch(() => form.data(), () => {
        if (enabled() && form.isDirty) schedule();
    }, { deep: true });

    const flush = (done = () => {}, onError = () => {}) => {
        if (!enabled() || !form.isDirty) {
            done();
            return;
        }
        run({ onSuccess: done, onError });
    };

    onBeforeUnmount(() => {
        if (enabled() && hasChanges()) run();
        clearTimeout(timer);
    });

    return { saving, savedAt, failed, flush, retry: () => run() };
}
