import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Keeps unvalidated typing alive across a page reload.
 *
 * The draft lives server-side and scoped to the account (see ADR-073): a
 * clinical workstation is shared, and browser storage is not partitioned by
 * user — one clinician must never inherit, and then save under their own
 * name, what a colleague typed but never validated.
 *
 * @param {object} options
 * @param {string} options.endpoint          PUT/DELETE URL of the draft
 * @param {object} options.forms             { sectionName: inertiaForm }
 * @param {object|null} options.initial      { payload, updated_at } served by the page
 * @param {boolean} options.enabled          false disables autosave entirely
 * @param {number} options.debounceMs
 */
export function useFormDraft({ endpoint, forms, initial = null, enabled = true, debounceMs = 1200 }) {
    const savedAt = ref(initial?.updated_at ?? null);
    const restored = ref(false);
    const saving = ref(false);
    let timer = null;
    let suspended = false;

    // A restored value must keep the shape the form expects. A null where a
    // string is expected would break every .trim() downstream and blank the
    // page — never let the transport decide the form's types.
    const restoreInto = (form, section) => {
        Object.entries(section ?? {}).forEach(([key, value]) => {
            if (!(key in form.data())) return;

            if (value === null) {
                form[key] = Array.isArray(form[key]) ? [] : '';

                return;
            }

            form[key] = value;
        });
    };

    if (initial?.payload) {
        Object.entries(forms).forEach(([name, form]) => {
            if (initial.payload[name] === undefined) return;

            restoreInto(form, initial.payload[name]);
            restored.value = true;
        });
    }

    const readCookie = (name) => document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`))
        ?.split('=')[1];

    const persist = async () => {
        if (suspended || !enabled) return;

        // Only what the clinician actually touched: a pristine form would
        // otherwise store its defaults and look like real typing.
        const payload = {};

        Object.entries(forms).forEach(([name, form]) => {
            if (form.isDirty) payload[name] = form.data();
        });

        // Rien n'est modifié : un minuteur déjà armé quand le formulaire vient d'être
        // enregistré ou vidé n'a plus rien à garder. L'envoyer serait un 422
        // (« payload obligatoire ») pour rien.
        if (Object.keys(payload).length === 0) return;

        saving.value = true;

        try {
            const response = await fetch(endpoint, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
                },
                body: JSON.stringify({ payload }),
            });

            if (response.ok) {
                savedAt.value = (await response.json()).saved_at;
            }
        } catch {
            // Offline or expired session: what is on screen stays and the
            // real save remains available. Never block on a draft.
        } finally {
            saving.value = false;
        }
    };

    const observe = (form) => {
        if (!enabled) return;

        watch(
            () => form.data(),
            () => {
                if (suspended || !form.isDirty) return;

                clearTimeout(timer);
                timer = setTimeout(persist, debounceMs);
            },
            { deep: true },
        );
    };

    Object.values(forms).forEach(observe);

    /**
     * Rattacher un formulaire qui vit dans un composant enfant.
     *
     * Les formulaires de « Conduite à tenir » — demande de chirurgie,
     * d'hospitalisation, de transfert — appartiennent à
     * `ClinicalOrientationCard`, et non à la page. Tant qu'ils n'étaient
     * pas rattachés ici, tout ce que le médecin y saisissait disparaissait
     * à l'actualisation : ce sont pourtant les formulaires les plus longs
     * du parcours (motif, résumé clinique, traitement prévu).
     *
     * L'enregistrement restaure immédiatement ce que le brouillon portait
     * déjà pour cette section, puis l'observe comme les autres.
     */
    const register = (name, form) => {
        forms[name] = form;

        if (initial?.payload?.[name] !== undefined) {
            restoreInto(form, initial.payload[name]);
            restored.value = true;
        }

        observe(form);
    };

    onBeforeUnmount(() => clearTimeout(timer));

    /** Call from a real save's onSuccess: the typing has become a record. */
    const markSaved = () => {
        savedAt.value = null;
        restored.value = false;
        clearTimeout(timer);
    };

    /** Stop autosaving while a discard request is in flight. */
    const suspend = () => {
        suspended = true;
        clearTimeout(timer);
    };
    const resume = () => { suspended = false; };

    return { savedAt, restored, saving, persist, markSaved, suspend, resume, register };
}
