<script setup>
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { CalendarClock, Loader2, Lock, Stethoscope, UsersRound } from 'lucide-vue-next';

/**
 * Corriger UN fait de la programmation, ouvert par le crayon de sa tuile
 * (amendements de l'ADR-168).
 *
 *   avant le démarrage   POST /schedule — planning RH vérifié : un chirurgien
 *                        absent du planning est montré verrouillé, avec la raison
 *   au bloc              POST /team-adjustment — motif obligatoire, audité ; le
 *                        planning n'a plus d'objet, le patient est sur la table
 *
 * Seul le champ corrigé est envoyé (ou, pour /schedule, les autres tels
 * qu'ils sont) : rien d'autre ne bouge. Le serveur rejuge tout.
 */
const props = defineProps({
    surgicalRequest: { type: Object, required: true },
    /** 'date' | 'principal' | 'assistants' | 'operator' */
    field: { type: String, required: true },
});
const emit = defineEmits(['close']);

const META = {
    date: { title: 'Date et heure programmées', icon: CalendarClock },
    principal: { title: 'Chirurgien principal', icon: Stethoscope },
    assistants: { title: 'Chirurgiens aides', icon: UsersRound },
    operator: { title: 'Opérateur (qui a réellement opéré)', icon: Stethoscope },
};

const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const inTheatre = computed(() => props.surgicalRequest.status === 'IN_PROGRESS');
const principal = computed(() => props.surgicalRequest.surgeon ?? null);
const currentAssistantIds = (props.surgicalRequest.team_members ?? [])
    .filter((member) => member.function === 'SURGEON')
    .map((member) => member.user?.id)
    .filter(Boolean);
const currentOperatorId = props.surgicalRequest.intervention?.performed_by?.id ?? null;

// Valeur d'un <input datetime-local> dans l'heure du poste.
const toLocalInput = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const form = useForm({
    scheduled_at: toLocalInput(props.surgicalRequest.scheduled_at),
    surgeon_id: principal.value?.id ?? null,
    assistant_surgeon_ids: [...currentAssistantIds],
    performed_by: currentOperatorId,
    reason: '',
});

const surgeons = ref([]);
const loading = ref(props.field !== 'date');
const loadError = ref(null);

onMounted(async () => {
    if (props.field === 'date') return;
    const at = inTheatre.value
        ? (props.surgicalRequest.intervention?.started_at ?? props.surgicalRequest.scheduled_at)
        : props.surgicalRequest.scheduled_at;
    try {
        const response = await fetch(`${base.value}/surgeons?at=${encodeURIComponent(at ?? new Date().toISOString())}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error();
        surgeons.value = (await response.json()).data ?? [];
    } catch {
        loadError.value = 'La liste des chirurgiens n’a pas pu être chargée.';
    } finally {
        loading.value = false;
    }
});

// Au bloc, le planning n'a plus d'objet : tout chirurgien est choisissable.
const selectable = (surgeon) => inTheatre.value || surgeon.selectable !== false;
const nameOf = (id) => surgeons.value.find((surgeon) => surgeon.id === id)?.name
    ?? (id === principal.value?.id ? principal.value?.name : null)
    ?? `Chirurgien #${id}`;

const assistantCandidates = computed(() => surgeons.value.filter((surgeon) => surgeon.id !== form.surgeon_id));
const toggleAssistant = (id, checked) => {
    const set = new Set(form.assistant_surgeon_ids);
    checked ? set.add(id) : set.delete(id);
    form.assistant_surgeon_ids = [...set];
};
const operatorChoices = computed(() => [principal.value?.id, ...currentAssistantIds].filter(Boolean));

const changed = computed(() => {
    const sameSet = (a, b) => a.length === b.length && a.every((id) => b.includes(id));
    return {
        date: form.scheduled_at && form.scheduled_at !== toLocalInput(props.surgicalRequest.scheduled_at),
        principal: form.surgeon_id && form.surgeon_id !== principal.value?.id,
        assistants: !sameSet(form.assistant_surgeon_ids, currentAssistantIds),
        operator: form.performed_by && form.performed_by !== currentOperatorId,
    }[props.field];
});
const canSubmit = computed(() => changed.value && (!inTheatre.value || form.reason.trim().length >= 3) && !form.processing);

const submit = () => {
    if (inTheatre.value) {
        form.transform((data) => ({
            reason: data.reason,
            ...(props.field === 'date' && { scheduled_at: data.scheduled_at }),
            ...(props.field === 'principal' && { surgeon_id: data.surgeon_id }),
            ...(props.field === 'assistants' && { assistant_surgeon_ids: data.assistant_surgeon_ids }),
            ...(props.field === 'operator' && { performed_by: data.performed_by }),
        })).post(`${base.value}/team-adjustment`, { preserveScroll: true, onSuccess: () => emit('close') });
        return;
    }

    form.transform((data) => ({
        surgeon_id: data.surgeon_id,
        scheduled_at: data.scheduled_at,
        ...(props.field === 'assistants' && { assistant_surgeon_ids: data.assistant_surgeon_ids }),
        // Un nouveau principal qui était aide quitte les aides.
        ...(props.field === 'principal' && { assistant_surgeon_ids: currentAssistantIds.filter((id) => id !== data.surgeon_id) }),
    })).post(`${base.value}/schedule`, { preserveScroll: true, onSuccess: () => emit('close') });
};

const errorFor = (...keys) => keys.map((key) => form.errors[key]).find(Boolean);
</script>

<template>
    <form class="space-y-4 rounded-lg border border-primary/40 bg-primary/5 p-4" @submit.prevent="submit">
        <div class="flex items-center gap-2">
            <component :is="META[field].icon" class="h-4 w-4 text-primary" aria-hidden="true" />
            <h4 class="text-sm font-semibold text-foreground">Modifier : {{ META[field].title }}</h4>
        </div>

        <!-- Date -->
        <FormField v-if="field === 'date'" label="Nouvelle date et heure" :error="errorFor('scheduled_at', 'surgeon_id', 'assistant_surgeon_ids.0')">
            <DateTimePicker id="edit_scheduled_at" v-model="form.scheduled_at" format="long" required />
            <p v-if="inTheatre" class="mt-1 text-xs text-muted-foreground">L’heure réelle de l’intervention reste son « Début », consigné dans la section Intervention.</p>
        </FormField>

        <template v-else>
            <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" />Chargement des chirurgiens…</p>
            <p v-else-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

            <!-- Chirurgien principal -->
            <FormField v-else-if="field === 'principal'" label="Nouveau chirurgien principal" :error="errorFor('surgeon_id', 'scheduled_at', 'performed_by')">
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="surgeon in surgeons" :key="surgeon.id">
                        <button
                            type="button"
                            :disabled="!selectable(surgeon)"
                            :aria-pressed="form.surgeon_id === surgeon.id"
                            :class="cn('flex w-full items-start gap-2.5 rounded-lg border px-3 py-2 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-60', form.surgeon_id === surgeon.id ? 'border-primary bg-card ring-1 ring-primary/40' : 'border-border bg-card hover:bg-muted/40')"
                            @click="form.surgeon_id = surgeon.id"
                        >
                            <component :is="selectable(surgeon) ? Stethoscope : Lock" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-foreground">{{ surgeon.name }}</span>
                                <span v-if="!inTheatre && surgeon.label" class="block text-xs text-muted-foreground">{{ surgeon.label }}</span>
                            </span>
                            <Badge v-if="surgeon.id === principal?.id" variant="outline">Actuel</Badge>
                            <Badge v-else-if="surgeon.is_me" variant="outline">Moi</Badge>
                        </button>
                    </li>
                </ul>
            </FormField>

            <!-- Aides -->
            <FormField v-else-if="field === 'assistants'" label="Chirurgiens aides" :error="errorFor('assistant_surgeon_ids', 'assistant_surgeon_ids.0', 'performed_by')">
                <p v-if="!assistantCandidates.length" class="text-sm text-muted-foreground">Aucun autre compte ne porte le profil Chirurgien.</p>
                <ul v-else class="grid gap-2 sm:grid-cols-2">
                    <li v-for="surgeon in assistantCandidates" :key="surgeon.id">
                        <label
                            :for="`edit-assistant-${surgeon.id}`"
                            :class="cn('flex items-start gap-2.5 rounded-lg border bg-card px-3 py-2 transition-colors', selectable(surgeon) ? 'cursor-pointer hover:bg-muted/40' : 'cursor-not-allowed opacity-60', form.assistant_surgeon_ids.includes(surgeon.id) ? 'border-primary' : 'border-border')"
                        >
                            <Checkbox
                                :id="`edit-assistant-${surgeon.id}`"
                                class="mt-0.5"
                                :disabled="!selectable(surgeon) && !form.assistant_surgeon_ids.includes(surgeon.id)"
                                :model-value="form.assistant_surgeon_ids.includes(surgeon.id)"
                                @update:model-value="(checked) => toggleAssistant(surgeon.id, checked)"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-foreground">{{ surgeon.name }}</span>
                                <span v-if="!inTheatre && surgeon.label" class="block text-xs text-muted-foreground">{{ surgeon.label }}</span>
                            </span>
                            <Badge v-if="surgeon.is_me" variant="outline">Moi</Badge>
                        </label>
                    </li>
                </ul>
            </FormField>

            <!-- Opérateur -->
            <FormField v-else-if="field === 'operator'" label="Qui a réellement opéré ?" hint="Parmi le chirurgien principal et les aides. Pour un autre chirurgien, ajoutez-le d’abord aux aides." :error="errorFor('performed_by')">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="id in operatorChoices"
                        :key="id"
                        type="button"
                        :aria-pressed="form.performed_by === id"
                        :class="cn('inline-flex items-center gap-1.5 rounded-lg border bg-card px-3 py-1.5 text-sm transition-colors', form.performed_by === id ? 'border-primary font-medium ring-1 ring-primary/40' : 'border-border hover:bg-muted/40')"
                        @click="form.performed_by = id"
                    >
                        <Stethoscope class="h-3.5 w-3.5" aria-hidden="true" />{{ nameOf(id) }}
                        <span v-if="id === principal?.id" class="text-xs text-muted-foreground">· principal</span>
                    </button>
                </div>
            </FormField>
        </template>

        <FormField v-if="inTheatre" label="Motif de la correction" hint="Obligatoire au bloc : il est conservé dans l’historique du dossier." :error="errorFor('reason')">
            <Textarea id="edit_schedule_reason" v-model="form.reason" rows="2" placeholder="Ex. heure décalée par l’anesthésie, renfort arrivé à 14 h 30…" required />
        </FormField>

        <p v-if="errorFor('team')" class="text-xs text-destructive">{{ errorFor('team') }}</p>

        <div class="flex justify-end gap-2">
            <Button size="sm" variant="white-outline" type="button" @click="emit('close')">Annuler</Button>
            <Button size="sm" type="submit" :disabled="!canSubmit">Enregistrer</Button>
        </div>
    </form>
</template>
