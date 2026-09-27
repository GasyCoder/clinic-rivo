<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Check,
    CircleAlert,
    ClipboardList,
    FileText,
    ListChecks,
    MessageSquareText,
    Pencil,
    Pill,
    Siren,
    Stethoscope,
    X,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';

/**
 * ADR-113 — la demande d'hospitalisation du médecin, complétée sur le séjour.
 *
 * Chaque rubrique se corrige seule, depuis son crayon : le serveur n'écrit que
 * ce qui est envoyé (omettre n'efface pas, ADR-074), si bien que corriger un
 * diagnostic ne réécrit jamais le motif d'un collègue. Une seule rubrique est
 * ouverte à la fois. La priorité se lit — et se corrige — dans l'en-tête.
 *
 * Le motif et le diagnostic d'entrée restent dits (« À préciser ») quand ils
 * manquent ; les autres rubriques vides ne s'affichent qu'à qui peut les
 * compléter.
 */
const props = defineProps({
    stayUuid: { type: String, required: true },
    request: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    priorities: { type: Array, required: true },
});

const SECTIONS = [
    { key: 'reason', icon: MessageSquareText, label: 'Motif', always: true, rows: 4, max: 3000 },
    { key: 'admission_diagnosis', icon: Stethoscope, label: 'Diagnostic d’entrée', always: true, rows: 3, max: 3000 },
    { key: 'clinical_summary', icon: FileText, label: 'Résumé clinique et examens', rows: 6, max: 5000 },
    { key: 'planned_treatment', icon: Pill, label: 'Traitement prévu', rows: 3, max: 3000 },
    { key: 'instructions', icon: ListChecks, label: 'Consignes au service', rows: 3, max: 3000 },
];

const priority = computed(() => props.priorities.find((option) => option.value === props.request.priority) ?? null);
const incomplete = computed(() => !props.request.reason || !props.request.admission_diagnosis);
const sections = computed(() => SECTIONS
    .map((section) => ({ ...section, value: props.request[section.key] }))
    .filter((section) => section.always || section.value || props.canEdit));

// ── Corriger une rubrique ───────────────────────────────────────────────────
// Une seule rubrique ouverte ; seule sa valeur part au serveur.
const editingKey = ref(null);
const form = useForm({ value: '' });
const editingSection = computed(() => SECTIONS.find((section) => section.key === editingKey.value) ?? null);
const currentValue = (key) => (key === 'priority' ? props.request.priority : props.request[key]) ?? '';
const edit = (key) => {
    form.clearErrors();
    form.value = key === 'priority' ? (props.request.priority ?? 'NORMAL') : currentValue(key);
    editingKey.value = key;
    nextTick(() => document.getElementById(`stay-request-${key}`)?.focus());
};
const cancel = () => {
    editingKey.value = null;
    form.clearErrors();
    remeasure();
};
const unchanged = computed(() => editingKey.value !== null
    && String(form.value ?? '').trim() === String(currentValue(editingKey.value)).trim());
const save = () => {
    const key = editingKey.value;
    if (!key || form.processing) return;
    if (unchanged.value) {
        cancel();

        return;
    }
    form.transform((data) => ({ [key]: data.value }))
        .put(`/hospitalisation/${props.stayUuid}/demande`, {
            preserveScroll: true,
            onSuccess: () => {
                editingKey.value = null;
                remeasure();
            },
        });
};
const fieldError = computed(() => (editingKey.value ? form.errors[editingKey.value] : null) ?? form.errors.hospitalization_request ?? null);

// ── Lire la suite ───────────────────────────────────────────────────────────
// Un texte de plus de quatre lignes se replie. Le bouton n'apparaît que si le
// texte déborde réellement à cette largeur, mesuré une fois la page montée —
// jamais pendant le rendu serveur — puis à chaque changement de taille du
// texte, y compris quand la police de l'application finit de charger (mesuré
// avant, sur une police de secours plus large, il se montrait à tort).
const opened = reactive({});
const overflowing = reactive({});
const textElements = {};
let resizeObserver = null;
const measure = () => {
    Object.entries(textElements).forEach(([key, element]) => {
        if (!opened[key]) overflowing[key] = element.scrollHeight > element.clientHeight + 1;
    });
};
const remeasure = () => nextTick(measure);
const textRef = (key) => (element) => {
    if (element) {
        textElements[key] = element;
        resizeObserver?.observe(element);
    } else if (textElements[key]) {
        resizeObserver?.unobserve(textElements[key]);
        delete textElements[key];
    }
};
onMounted(() => {
    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(measure);
        Object.values(textElements).forEach((element) => resizeObserver.observe(element));
    }
    window.addEventListener('resize', measure);
    document.fonts?.ready?.then(measure);
    remeasure();
});
onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', measure);
});
watch(() => SECTIONS.map((section) => props.request[section.key] ?? '').join('\u0000'), remeasure);
</script>

<template>
    <Card class="flex flex-col overflow-hidden">
        <header class="flex items-start gap-3 border-b border-border px-5 py-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                <ClipboardList class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-foreground">Demande d’hospitalisation</h2>
                    <template v-if="editingKey !== 'priority'">
                        <Badge v-if="priority" :variant="request.priority === 'URGENT' ? 'destructive' : 'outline'">
                            <Siren v-if="request.priority === 'URGENT'" class="me-1 h-3 w-3" aria-hidden="true" />Priorité {{ priority.label.toLowerCase() }}
                        </Badge>
                        <Button
                            v-if="canEdit"
                            type="button"
                            size="icon-xs"
                            variant="ghost"
                            class="text-muted-foreground"
                            :disabled="editingKey !== null"
                            title="Modifier la priorité"
                            aria-label="Modifier la priorité"
                            @click="edit('priority')"
                        ><Pencil class="h-3.5 w-3.5" /></Button>
                    </template>
                </div>
                <p v-if="request.requested_by || request.requested_at" class="mt-0.5 text-xs text-muted-foreground">
                    Demandée<template v-if="request.requested_by"> par {{ doctorName(request.requested_by) }}</template><template v-if="request.requested_at"> · {{ formatDateTime(request.requested_at) }}</template>
                </p>
                <form v-if="editingKey === 'priority'" class="mt-2.5 flex flex-wrap items-center gap-2" @submit.prevent="save" @keydown.esc.prevent="cancel">
                    <Select id="stay-request-priority" v-model="form.value" :options="priorities" aria-label="Priorité" class="w-44" />
                    <Button type="submit" size="sm" :disabled="form.processing || unchanged"><Check class="h-4 w-4" />Enregistrer</Button>
                    <Button type="button" size="sm" variant="ghost" :disabled="form.processing" @click="cancel">Annuler</Button>
                    <FormError :message="fieldError" class="w-full" />
                </form>
            </div>
        </header>

        <div class="flex-1 px-5 py-4">
            <p v-if="incomplete" class="mb-4 flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                <CircleAlert class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Motif ou diagnostic d’entrée encore à préciser.
            </p>
            <dl class="divide-y divide-border">
                <div v-for="section in sections" :key="section.key" class="flex gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground" aria-hidden="true">
                        <component :is="section.icon" class="h-3.5 w-3.5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-xs font-medium text-muted-foreground">{{ section.label }}</dt>
                            <Button
                                v-if="canEdit && editingKey !== section.key"
                                type="button"
                                size="icon-xs"
                                variant="ghost"
                                class="-my-1 text-muted-foreground"
                                :disabled="editingKey !== null"
                                :title="`Modifier « ${section.label} »`"
                                :aria-label="`Modifier « ${section.label} »`"
                                @click="edit(section.key)"
                            ><Pencil class="h-3.5 w-3.5" /></Button>
                        </div>

                        <!-- La rubrique ouverte : seule sa valeur part au serveur. -->
                        <form v-if="editingKey === section.key" class="mt-1.5 space-y-2" @submit.prevent="save">
                            <Textarea
                                :id="`stay-request-${section.key}`"
                                v-model="form.value"
                                :rows="section.rows"
                                :maxlength="section.max"
                                :aria-label="section.label"
                                @keydown.esc.prevent="cancel"
                                @keydown.ctrl.enter.prevent="save"
                                @keydown.meta.enter.prevent="save"
                            />
                            <FormError :message="fieldError" />
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] text-muted-foreground">
                                    Échap pour annuler · Ctrl+Entrée pour enregistrer<template v-if="editingSection"> · {{ String(form.value ?? '').length }}/{{ editingSection.max }}</template>
                                </p>
                                <div class="flex gap-2">
                                    <Button type="button" size="sm" variant="ghost" :disabled="form.processing" @click="cancel"><X class="h-4 w-4" />Annuler</Button>
                                    <Button type="submit" size="sm" :disabled="form.processing || unchanged"><Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}</Button>
                                </div>
                            </div>
                        </form>

                        <template v-else>
                            <dd
                                v-if="section.value"
                                :ref="textRef(section.key)"
                                :class="['mt-0.5 whitespace-pre-line text-sm leading-relaxed text-foreground', opened[section.key] ? '' : 'line-clamp-4']"
                            >{{ section.value }}</dd>
                            <dd v-else class="mt-0.5 text-sm italic text-muted-foreground">{{ section.always ? 'À préciser' : 'Non renseigné' }}</dd>
                            <button
                                v-if="section.value && (overflowing[section.key] || opened[section.key])"
                                type="button"
                                class="mt-1 text-xs font-semibold text-primary hover:underline"
                                :aria-expanded="Boolean(opened[section.key])"
                                @click="opened[section.key] = !opened[section.key]"
                            >{{ opened[section.key] ? 'Replier' : 'Lire la suite' }}</button>
                        </template>
                    </div>
                </div>
            </dl>
        </div>
    </Card>
</template>
