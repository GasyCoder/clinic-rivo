<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';

/**
 * Renders exactly what the server says about each step — never a status
 * derived here from whether some data happens to be present. Opening a step
 * is not finishing it, and a step declared unnecessary is not a step done:
 * both distinctions only exist in `consultation_steps`.
 */
const props = defineProps({
    steps: { type: Array, required: true },
    currentKey: { type: String, required: true },
    orientationUuid: { type: String, required: true },
});

const currentIndex = computed(() => Math.max(
    0,
    props.steps.findIndex((step) => step.key === props.currentKey),
));
const activeStep = computed(() => props.steps[currentIndex.value] ?? props.steps[0]);

/** Relevant steps only: an irrelevant step is never an omission to make up. */
const relevantSteps = computed(() => props.steps.filter((step) => step.relevant !== false));
const resolvedCount = computed(() => relevantSteps.value.filter((step) => step.resolved).length);
const progress = computed(() => (relevantSteps.value.length === 0
    ? 0
    : Math.round((resolvedCount.value / relevantSteps.value.length) * 100)));

/**
 * Every step stays reachable. A doctor may need to come back to the
 * interrogation after the examination, and a step marked unnecessary can
 * still be opened if the encounter turns out to be a real consultation.
 */
const stepHref = (step) => `/medicine/orientations/${props.orientationUuid}/${step.key}`;

const MARKERS = {
    COMPLETED: {
        icon: 'check',
        ring: 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
        note: 'Validée',
        noteClass: 'text-emerald-600 dark:text-emerald-400',
    },
    SKIPPED: {
        icon: 'forward-arrow',
        ring: 'border-slate-300 bg-slate-100 text-slate-500 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-400',
        note: 'Non nécessaire',
        noteClass: 'text-slate-400',
    },
    IN_PROGRESS: {
        icon: 'clock',
        ring: 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
        note: 'En cours',
        noteClass: 'text-amber-600 dark:text-amber-400',
    },
    NOT_STARTED: {
        icon: null,
        ring: 'border-gray-300 bg-white text-slate-400 dark:border-gray-800 dark:bg-gray-1000 dark:text-slate-500',
        note: null,
        noteClass: 'text-slate-400 dark:text-slate-500',
    },
};

const marker = (step) => MARKERS[step.status] ?? MARKERS.NOT_STARTED;
const markerIcon = (step) => marker(step).icon ?? step.icon;

/**
 * The second line under a label. `keep` marks the ones that must survive on a
 * narrow bar: what the server computed ("2 examens demandés"), a step without
 * object for this patient, and wherever the doctor currently stands. The
 * plain status is not among them — the coloured marker and its icon already
 * say "validée" or "non nécessaire", and repeating it in text is what pushed
 * the frieze past the width it had.
 */
const subtitle = (step) => {
    const isCurrent = step.key === props.currentKey;

    if (step.note) return { text: step.note, class: marker(step).noteClass, keep: true };
    if (step.relevant === false) return { text: 'Sans objet', class: 'text-slate-400 dark:text-slate-500', keep: true };
    if (marker(step).note) return { text: marker(step).note, class: marker(step).noteClass, keep: isCurrent };

    return { text: step.hint ?? '', class: 'text-slate-400 dark:text-slate-500', keep: isCurrent };
};

/** The line leading into a step is coloured by the step it comes from. */
const connectorClass = (index) => (props.steps[index - 1]?.resolved
    ? 'bg-emerald-300 dark:bg-emerald-800'
    : 'bg-gray-200 dark:bg-gray-800');
</script>

<template>
    <section class="workflow-bar overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950" aria-labelledby="medicine-workflow-title">
        <div class="flex items-center gap-3 px-3 py-2.5 sm:px-4">
            <!-- The active step's name is already the heading of the card
                 directly below; repeating it here only stole the width that
                 "Interrogatoire" and "Décision" need to read in full. -->
            <h2 id="medicine-workflow-title" class="sr-only">Parcours médical — étape : {{ activeStep.label }}</h2>

            <!-- Natural widths, never a fixed column count. A `grid-cols-N`
                 tied to the number of steps has now broken three times: the
                 pathway went from six to seven steps, then back to six, each
                 time leaving an orphan column and cells far wider than their
                 label. Here every cell stays the size of what it says, and the
                 slack — when there is any — goes into the connecting lines. -->
            <ol class="flex min-w-0 flex-1 items-center" aria-label="Étapes de la consultation">
                <!-- `flex-auto`, jamais `flex-1` : une base de 0 laisserait la
                     cellule passer sous la largeur de son propre libellé, et
                     « Interrogatoire » se faisait tronquer alors que la ligne
                     avait encore de la place. Ici la base est le contenu, et
                     seul le surplus part dans le trait de liaison. -->
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    :class="['flex min-w-0 items-center', index === 0 ? 'shrink-0' : 'flex-auto']"
                >
                    <span v-if="index > 0" aria-hidden="true" :class="['workflow-connector mx-1.5 h-0.5 flex-1 rounded-full', connectorClass(index)]" />

                    <Link
                        :href="stepHref(step)"
                        :aria-current="step.key === currentKey ? 'step' : undefined"
                        :title="step.blocker ?? subtitle(step).text ?? undefined"
                        :class="[
                            'workflow-step-link group flex min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 transition-colors',
                            step.key === currentKey
                                ? 'bg-primary-50 ring-1 ring-inset ring-primary-200 dark:bg-primary-950/30 dark:ring-primary-900'
                                : 'hover:bg-gray-50 dark:hover:bg-gray-1000/60',
                        ]"
                    >
                        <span :class="[
                            'workflow-marker flex h-9 w-9 shrink-0 items-center justify-center rounded-full border',
                            step.key === currentKey
                                ? 'border-primary-600 bg-primary-600 text-white shadow-sm'
                                : marker(step).ring,
                        ]">
                            <Icon class="text-base" :name="markerIcon(step)" />
                        </span>

                        <!-- Trop étroit pour six libellés : seule l'étape en
                             cours garde le sien, les autres se lisent à leur
                             pastille et à leur infobulle. Les réduire à 9 px
                             pour les faire tenir est exactement ce dont cette
                             barre sort. -->
                        <span :class="['min-w-0', step.key === currentKey ? 'workflow-step-text--current' : 'workflow-step-text']">
                            <!-- Le libellé ne se tronque jamais : c'est le
                                 sous-titre qui cède la place en premier. -->
                            <span :class="['block whitespace-nowrap text-sm font-bold leading-tight', step.key === currentKey ? 'text-primary-700 dark:text-primary-300' : 'text-slate-700 dark:text-slate-200']">{{ step.navLabel ?? step.label }}</span>
                            <!-- La note du serveur prime : « Non nécessaire »
                                 ou « 2 examens demandés » disent plus que le
                                 statut seul. -->
                            <span
                                v-if="subtitle(step).text"
                                :class="[
                                    'mt-0.5 truncate text-[11px] font-medium leading-tight',
                                    subtitle(step).keep ? 'block' : 'workflow-step-note',
                                    subtitle(step).class,
                                ]"
                            >{{ subtitle(step).text }}</span>
                        </span>
                    </Link>
                </li>
            </ol>

            <div class="hidden shrink-0 border-s border-gray-200 ps-3 text-end dark:border-gray-900 sm:block">
                <p class="text-base font-extrabold leading-none text-slate-700 dark:text-white">{{ resolvedCount }}<span class="text-slate-400">/{{ relevantSteps.length }}</span></p>
                <p class="mt-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Validées</p>
            </div>
        </div>

        <div class="h-1 bg-gray-100 dark:bg-gray-900" aria-hidden="true">
            <div class="h-full bg-primary-600 transition-all duration-300" :style="{ width: `${progress}%` }" />
        </div>
    </section>
</template>

<style scoped>
/**
 * La barre décide d'après SA largeur, pas celle de l'écran. Le viewport est
 * un mauvais indicateur ici : la barre latérale de la page fait qu'à 1280 px
 * cette barre ne mesure que 828 px, alors qu'à 1024 px elle en mesure 860.
 * Un point de rupture `lg:` masquait donc les libellés là où ils tenaient, et
 * les tronquait là où ils ne tenaient pas.
 */
.workflow-bar {
    container-type: inline-size;
}

/* Palier 0 — téléphone : six pastilles de 36 px ne tiennent plus dans la
   rangée et se faisaient écraser sous leur propre taille. Elles rétrécissent
   plutôt que de se déformer. */
@container (max-width: 32rem) {
    .workflow-marker {
        height: 2rem;
        width: 2rem;
    }

    .workflow-step-link {
        padding-left: 0.25rem;
        padding-right: 0.25rem;
    }
}

/* Palier 1 — barre étroite : pastilles, et le libellé de l'étape en cours. */
.workflow-step-text {
    display: none;
}

.workflow-step-text--current {
    display: block;
}

.workflow-step-note {
    display: none;
}

/* Un trait de liaison n'a de sens que s'il reste large de quelques pixels :
   faute de place il se réduisait à un point entre deux étapes. Il n'apparaît
   donc qu'avec le palier qui lui laisse de la longueur, et sa place revient
   aux libellés partout ailleurs. */
.workflow-connector {
    display: none;
}

/* Palier 2 — les six libellés tiennent. */
@container (min-width: 57rem) {
    .workflow-step-text {
        display: block;
    }
}

/* Palier 3 — place pour les traits de liaison et le statut en toutes lettres. */
@container (min-width: 68rem) {
    .workflow-connector {
        display: block;
    }

    .workflow-step-note {
        display: block;
    }
}
</style>
