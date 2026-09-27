<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';
import { toDatetimeLocalInput } from '@/utilities/date';
import { AlertTriangle, CalendarDays, CalendarX2, Crown, Info, LoaderCircle, Lock, UserCheck } from 'lucide-vue-next';

/**
 * ADR-168 — programmer l'intervention : la date, puis les chirurgiens.
 *
 * Un compte portant le profil Chirurgien coche « Moi-même » ; tout compte peut
 * aussi choisir un, deux, trois chirurgiens parmi ceux que le planning RH dit
 * disponibles à l'heure programmée. Le premier choix — ou « Moi-même » — est le
 * chirurgien principal ; les autres entrent dans l'équipe de bloc comme aides.
 *
 * La disponibilité vient du serveur (`/surgeons?at=`), jamais d'un calcul ici,
 * et il la rejuge à l'enregistrement. Un chirurgien absent du planning est
 * montré verrouillé avec la raison, pas masqué : on sait pourquoi il manque.
 */
const props = defineProps({
    surgicalRequest: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const MAX_ASSISTANTS = 5;
const page = usePage();
const me = computed(() => page.props.auth?.user ?? null);
const base = computed(() => `/surgery/${props.surgicalRequest.uuid}`);
const alreadyScheduled = computed(() => Boolean(props.surgicalRequest.surgeon));

const currentAssistants = (props.surgicalRequest.team_members ?? [])
    .filter((member) => member.function === 'SURGEON')
    .map((member) => member.user_id ?? member.user?.id)
    .filter(Boolean);

const form = useForm({
    surgeon_id: '',
    assistant_surgeon_ids: [],
    scheduled_at: toDatetimeLocalInput(props.surgicalRequest.scheduled_at),
});

/** Ordre de choix : le premier est le principal. */
const selected = ref([
    ...(props.surgicalRequest.surgeon?.id ? [props.surgicalRequest.surgeon.id] : []),
    ...currentAssistants.filter((id) => id !== props.surgicalRequest.surgeon?.id),
]);

const toast = useToastStore();
/** Dit une seule fois par ouverture du formulaire, pas à chaque changement d'heure. */
let unlinkedNotified = false;
const UNLINKED_MESSAGE = '« Non vérifié » : aucune fiche RH n’est reliée à ce compte, le planning ne peut rien dire. Elle se relie en modifiant le compte, dans « Utilisateurs » (Personnel clinique).';

const roster = ref([]);
const loading = ref(false);
const loadError = ref('');
const loadedFor = ref('');
let timer = null;

const load = async () => {
    const at = form.scheduled_at;
    if (!at) {
        roster.value = [];
        loadedFor.value = '';
        return;
    }

    loading.value = true;
    loadError.value = '';
    try {
        const response = await fetch(`${base.value}/surgeons?at=${encodeURIComponent(at)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message ?? 'Liste des chirurgiens indisponible.');
        if (form.scheduled_at !== at) return;
        roster.value = payload.data ?? [];
        loadedFor.value = at;
        if (!unlinkedNotified && roster.value.some((surgeon) => surgeon.state === 'UNLINKED')) {
            unlinkedNotified = true;
            toast.info(UNLINKED_MESSAGE, 9000);
        }
    } catch (error) {
        loadError.value = error.message;
    } finally {
        loading.value = false;
    }
};

watch(() => form.scheduled_at, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
onMounted(load);
onBeforeUnmount(() => clearTimeout(timer));

const byId = computed(() => Object.fromEntries(roster.value.map((surgeon) => [surgeon.id, surgeon])));
const mine = computed(() => roster.value.find((surgeon) => surgeon.is_me) ?? null);
const iAmSurgeon = computed(() => me.value?.professional_profile?.code === 'SURGEON');
/**
 * Les choisis d'abord, dans l'ordre du choix (principal en tête) ; puis les
 * disponibles, le planning non relié, les verrouillés. L'ordre alphabétique du
 * serveur est gardé à l'intérieur de chaque groupe.
 */
const RANK = { AVAILABLE: 0, UNLINKED: 1, OFF_PLANNING: 2, INACTIVE_RECORD: 3 };
const orderOf = (surgeon) => (selected.value.includes(surgeon.id)
    ? selected.value.indexOf(surgeon.id)
    : 100 + (RANK[surgeon.state] ?? 9));
const others = computed(() => roster.value
    .filter((surgeon) => !surgeon.is_me)
    .slice()
    .sort((a, b) => orderOf(a) - orderOf(b)));

const isSelected = (id) => selected.value.includes(id);
const principalId = computed(() => selected.value[0] ?? null);
const nameOf = (id) => byId.value[id]?.name
    ?? (id === props.surgicalRequest.surgeon?.id ? props.surgicalRequest.surgeon.name : null)
    ?? props.surgicalRequest.team_members?.find((member) => (member.user_id ?? member.user?.id) === id)?.user?.name
    ?? 'Compte inconnu';

const toggle = (id, checked, { principal = false } = {}) => {
    if (checked && !isSelected(id)) {
        if (selected.value.length > MAX_ASSISTANTS) return;
        selected.value = principal ? [id, ...selected.value] : [...selected.value, id];
    } else if (!checked) {
        selected.value = selected.value.filter((value) => value !== id);
    }
    form.clearErrors();
};
const makePrincipal = (id) => {
    selected.value = [id, ...selected.value.filter((value) => value !== id)];
    form.clearErrors();
};

const STATE = {
    AVAILABLE: { variant: 'success', icon: UserCheck },
    UNLINKED: { variant: 'warning', icon: AlertTriangle },
    OFF_PLANNING: { variant: 'outline', icon: CalendarX2 },
    INACTIVE_RECORD: { variant: 'outline', icon: Lock },
};
const stateOf = (surgeon) => STATE[surgeon.state] ?? STATE.OFF_PLANNING;
const capitalize = (text) => (text ? text.charAt(0).toUpperCase() + text.slice(1) : '');

/** Un chirurgien déjà choisi que la nouvelle heure rend indisponible : dit, et bloque l'envoi. */
const blocked = computed(() => selected.value
    .map((id) => byId.value[id])
    .filter((surgeon) => surgeon && !surgeon.selectable));
const unknownSelected = computed(() => loadedFor.value
    ? selected.value.filter((id) => !byId.value[id])
    : []);
const reachedMax = computed(() => selected.value.length > MAX_ASSISTANTS);

const blocker = computed(() => {
    if (!form.scheduled_at) return 'Choisissez d’abord la date et l’heure : les chirurgiens disponibles en dépendent.';
    if (loading.value || loadedFor.value !== form.scheduled_at) return 'Vérification du planning…';
    if (!selected.value.length) return iAmSurgeon.value ? 'Cochez « Moi-même » ou choisissez au moins un chirurgien.' : 'Choisissez au moins un chirurgien.';
    if (unknownSelected.value.length) return `${unknownSelected.value.map(nameOf).join(', ')} n’a plus le profil Chirurgien : retirez-le.`;
    if (blocked.value.length) return `${blocked.value.map((surgeon) => surgeon.name).join(', ')} n’est pas disponible à cette heure : retirez-le ou changez l’heure.`;

    return null;
});

const assistantErrors = computed(() => Object.fromEntries(Object.entries(form.errors)
    .filter(([key]) => key.startsWith('assistant_surgeon_ids.'))
    .map(([key, message]) => [selected.value[Number(key.split('.')[1]) + 1], message])));
const rowError = (id) => (id === principalId.value ? form.errors.surgeon_id : assistantErrors.value[id]);

const submit = () => {
    if (blocker.value) return;
    form
        .transform((data) => ({
            ...data,
            surgeon_id: selected.value[0],
            assistant_surgeon_ids: selected.value.slice(1),
        }))
        .post(`${base.value}/schedule`, {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <p v-if="alreadyScheduled" class="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5 text-xs leading-5 text-muted-foreground">
            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            Erreur de saisie ? Les chirurgiens ou la date se corrigent tant que l’intervention n’a pas démarré.
        </p>

        <FormField as="div" label="Date et heure" required :error="form.errors.scheduled_at" class="max-w-sm">
            <DateTimePicker id="scheduled_at" v-model="form.scheduled_at" format="long" :invalid="Boolean(form.errors.scheduled_at)" placeholder="Choisir la date et l’heure de l’intervention" />
        </FormField>

        <div class="space-y-2" role="group" aria-labelledby="surgeons-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="surgeons-heading" class="text-sm font-medium text-foreground">Chirurgiens<span class="ms-0.5 text-destructive">*</span></h3>
                <span class="text-xs text-muted-foreground">Le premier choisi est le principal · {{ Math.max(selected.length - 1, 0) }}/{{ MAX_ASSISTANTS }} aide{{ selected.length - 1 > 1 ? 's' : '' }}</span>
            </div>

            <p v-if="!form.scheduled_at" class="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-3 text-sm text-muted-foreground">
                <CalendarDays class="h-4 w-4 shrink-0" aria-hidden="true" />Choisissez d’abord la date et l’heure : le planning RH dit alors qui est disponible.
            </p>
            <p v-else-if="loading && !roster.length" class="flex items-center gap-2 px-1 py-2 text-sm text-muted-foreground">
                <LoaderCircle class="h-4 w-4 animate-spin" aria-hidden="true" />Lecture du planning…
            </p>
            <p v-else-if="loadError" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ loadError }}</p>
            <p v-else-if="loadedFor && !roster.length" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                Aucun compte n’a encore le profil Chirurgien. Le Super Administrateur l’attribue dans Utilisateurs (profil métier du rôle Chirurgie).
            </p>

            <ul v-else-if="roster.length" :class="cn('divide-y divide-border overflow-hidden rounded-lg border border-border', loading && 'opacity-60')">
                <!-- Moi-même : seulement pour un compte au profil Chirurgien. -->
                <li v-if="iAmSurgeon && mine" :class="cn('flex flex-wrap items-center gap-3 px-3 py-2.5', isSelected(mine.id) ? 'bg-primary/5' : '')">
                    <Checkbox
                        id="surgeon-me"
                        :model-value="isSelected(mine.id)"
                        :disabled="!mine.selectable && !isSelected(mine.id)"
                        @update:model-value="(value) => toggle(mine.id, value, { principal: true })"
                    />
                    <label for="surgeon-me" class="min-w-0 flex-1 cursor-pointer">
                        <span class="block text-sm font-semibold text-foreground">Moi-même</span>
                        <span class="mt-0.5 flex items-start gap-1 text-xs text-muted-foreground">
                            <component :is="stateOf(mine).icon" class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />{{ mine.name }} · {{ capitalize(mine.label) }}
                        </span>
                    </label>
                    <div v-if="isSelected(mine.id)" class="flex w-full items-center gap-2 ps-7 sm:w-auto sm:ps-0">
                        <Badge v-if="principalId === mine.id" variant="default"><Crown class="h-3 w-3" />Principal</Badge>
                        <Button v-else size="xs" variant="ghost" type="button" @click="makePrincipal(mine.id)">Définir principal</Button>
                    </div>
                    <FormError v-if="rowError(mine.id)" class="basis-full">{{ rowError(mine.id) }}</FormError>
                </li>

                <li
                    v-for="surgeon in others"
                    :key="surgeon.id"
                    :class="cn('flex flex-wrap items-center gap-3 px-3 py-2.5', isSelected(surgeon.id) ? 'bg-primary/5' : '', !surgeon.selectable && !isSelected(surgeon.id) ? 'opacity-70' : '')"
                >
                    <Checkbox
                        :id="`surgeon-${surgeon.id}`"
                        :model-value="isSelected(surgeon.id)"
                        :disabled="(!surgeon.selectable || reachedMax) && !isSelected(surgeon.id)"
                        @update:model-value="(value) => toggle(surgeon.id, value)"
                    />
                    <label :for="`surgeon-${surgeon.id}`" class="min-w-0 flex-1 cursor-pointer">
                        <span class="block text-sm font-medium text-foreground">{{ surgeon.name }}</span>
                        <span class="mt-0.5 flex items-start gap-1 text-xs text-muted-foreground">
                            <component :is="stateOf(surgeon).icon" class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />{{ capitalize(surgeon.label) }}
                        </span>
                    </label>
                    <!-- Sur téléphone, les repères passent sous le nom au lieu de l'écraser. -->
                    <div v-if="isSelected(surgeon.id) || surgeon.state === 'UNLINKED'" class="flex w-full items-center gap-2 ps-7 sm:w-auto sm:ps-0">
                        <Badge v-if="principalId === surgeon.id" variant="default"><Crown class="h-3 w-3" />Principal</Badge>
                        <template v-else-if="isSelected(surgeon.id)">
                            <Badge variant="secondary">Aide</Badge>
                            <Button size="xs" variant="ghost" type="button" @click="makePrincipal(surgeon.id)">Définir principal</Button>
                        </template>
                        <Badge v-else :variant="stateOf(surgeon).variant">Non vérifié</Badge>
                    </div>
                    <FormError v-if="rowError(surgeon.id)" class="basis-full">{{ rowError(surgeon.id) }}</FormError>
                </li>
            </ul>

            <FormError v-if="form.errors.assistant_surgeon_ids">{{ form.errors.assistant_surgeon_ids }}</FormError>
            <FormError v-if="form.errors.surgeon_id && !rowError(principalId)">{{ form.errors.surgeon_id }}</FormError>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <p v-if="blocker" class="me-auto flex items-center gap-1.5 text-xs text-muted-foreground"><Info class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ blocker }}</p>
            <Button size="sm" variant="white-outline" type="button" @click="emit('close')">Annuler</Button>
            <Button size="sm" type="submit" :disabled="form.processing || Boolean(blocker)">{{ alreadyScheduled ? 'Corriger' : 'Programmer' }}</Button>
        </div>
    </form>
</template>
