<script setup>
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRightLeft,
    BedDouble,
    Building2,
    CalendarClock,
    Check,
    HeartPulse,
    History,
    Info,
    Pencil,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { formatDateTime } from '@/utilities/date';
import { doctorName } from '@/utilities/doctorName';

/**
 * ADR-161 / ADR-164 — où est le patient, depuis quand, à quel niveau de soins.
 *
 * Les repères se lisent d'un coup d'œil, chacun avec son icône ; les gestes
 * restent ceux du séjour : corriger l'emplacement (le crayon, sans rien
 * déplacer) et changer de lit (un nouvel emplacement, l'ancien gardé). Les
 * fenêtres de lit et de mutation vivent sur la page : la carte les demande.
 */
const props = defineProps({
    stay: { type: Object, required: true },
    movements: { type: Array, default: () => [] },
    capabilities: { type: Object, required: true },
    bedsConfigured: { type: Boolean, default: false },
});

const emit = defineEmits(['bed', 'move']);

const isActive = computed(() => props.stay.status === 'ACTIVE');
const needsBed = computed(() => props.bedsConfigured && isActive.value && !props.stay.bed_uuid);
const pastMovements = computed(() => props.movements.filter((movement) => !movement.is_current).slice().reverse());

const CARE_LEVELS = {
    STANDARD: { variant: 'outline', icon: ShieldCheck },
    CONTINUOUS: { variant: 'warning', icon: Activity },
    INTENSIVE: { variant: 'destructive', icon: HeartPulse },
};
const careLevel = computed(() => CARE_LEVELS[props.stay.care_level] ?? CARE_LEVELS.STANDARD);

// Jour de séjour : lu une fois la page montée, jamais pendant le rendu
// serveur — l'heure du serveur et celle du poste ne s'accordent pas toujours.
const stayDay = ref(null);
onMounted(() => {
    if (!props.stay.admitted_at || !isActive.value) return;
    const start = new Date(props.stay.admitted_at);
    const today = new Date();
    stayDay.value = Math.floor((Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())
        - Date.UTC(start.getFullYear(), start.getMonth(), start.getDate())) / 86_400_000) + 1;
});

const statusLine = computed(() => {
    if (isActive.value) return 'Hospitalisation en cours';
    const ended = props.stay.discharged_at ? ` le ${formatDateTime(props.stay.discharged_at)}` : '';

    return [`${props.stay.status_label}${ended}`, props.stay.end_reason_label].filter(Boolean).join(' · ');
});

// ── Service et chambre en saisie libre (site sans lits configurés) ──────────
const editingRoom = ref(false);
const roomForm = useForm({ service: props.stay.service ?? '', room_bed: props.stay.room_bed ?? '' });
const saveRoom = () => roomForm.put(`/hospitalisation/${props.stay.uuid}`, {
    preserveScroll: true,
    onSuccess: () => {
        roomForm.defaults();
        editingRoom.value = false;
    },
});
const cancelRoom = () => {
    editingRoom.value = false;
    roomForm.reset();
};

const canCorrectBed = computed(() => props.capabilities.can_update_stay && props.bedsConfigured && props.stay.bed_uuid);
const canCorrectRoom = computed(() => props.capabilities.can_update_stay && !props.bedsConfigured && !editingRoom.value);
const canMoveBed = computed(() => props.capabilities.can_move && props.bedsConfigured && props.stay.bed_uuid);
const canMoveFree = computed(() => props.capabilities.can_move && !props.bedsConfigured && !editingRoom.value);
</script>

<template>
    <Card class="flex flex-col overflow-hidden">
        <header class="flex items-start gap-3 border-b border-border px-5 py-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                <BedDouble class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-semibold text-foreground">Séjour</h2>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span>{{ statusLine }}</span>
                    <span v-if="stayDay" class="rounded bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-foreground">Jour {{ stayDay }}</span>
                </p>
            </div>
            <Button
                v-if="canCorrectBed"
                type="button"
                size="icon-xs"
                variant="ghost"
                class="text-muted-foreground"
                title="Corriger le lit (erreur de saisie)"
                aria-label="Corriger le lit actuel"
                @click="emit('bed', 'assign')"
            ><Pencil class="h-3.5 w-3.5" /></Button>
            <Button
                v-else-if="canCorrectRoom"
                type="button"
                size="icon-xs"
                variant="ghost"
                class="text-muted-foreground"
                title="Corriger l’emplacement actuel"
                aria-label="Corriger le service et la chambre actuels"
                @click="editingRoom = true"
            ><Pencil class="h-3.5 w-3.5" /></Button>
        </header>

        <div class="flex-1 space-y-4 px-5 py-4">
            <!-- ADR-164 — un patient au lit sans lit attribué se voit, et s'installe d'un geste. -->
            <div v-if="needsBed" class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" role="status">
                <BedDouble class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">Lit à attribuer</p>
                    <p class="mt-0.5 text-xs">Le patient est admis mais n’occupe encore aucun lit du site<template v-if="stay.room_bed"> (noté à la main : {{ stay.room_bed }})</template>.</p>
                    <Button v-if="capabilities.can_update_stay" type="button" size="sm" class="mt-2.5" @click="emit('bed', 'assign')"><BedDouble class="h-4 w-4" />Attribuer un lit</Button>
                </div>
            </div>

            <dl v-if="!editingRoom || bedsConfigured" class="grid gap-3 sm:grid-cols-2">
                <div class="flex items-start gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <Building2 class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Service</dt>
                        <dd :class="['text-sm', stay.service ? 'font-semibold text-foreground' : 'italic text-muted-foreground']">{{ stay.service || 'Non précisé' }}</dd>
                    </div>
                </div>
                <div v-if="!needsBed" class="flex items-start gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <BedDouble class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Chambre / lit</dt>
                        <dd :class="['text-sm', stay.room_bed ? 'font-semibold text-foreground' : 'italic text-muted-foreground']">{{ stay.room_bed || 'Non renseigné' }}</dd>
                    </div>
                </div>
                <div v-if="stay.care_level_label" class="flex items-start gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <component :is="careLevel.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Niveau de soins</dt>
                        <dd class="mt-0.5"><Badge :variant="careLevel.variant">{{ stay.care_level_label }}</Badge></dd>
                    </div>
                </div>
                <div class="flex items-start gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                    <CalendarClock class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Entrée</dt>
                        <dd class="text-sm font-semibold tabular-nums text-foreground">{{ formatDateTime(stay.admitted_at) }}</dd>
                        <dd v-if="stay.admitted_by" class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                            <UserRound class="h-3 w-3 shrink-0" aria-hidden="true" />Admis par {{ doctorName(stay.admitted_by) }}
                        </dd>
                    </div>
                </div>
            </dl>

            <form v-if="editingRoom && !bedsConfigured" class="space-y-3" @submit.prevent="saveRoom">
                <FormField label="Service" :error="roomForm.errors.service">
                    <Input v-model="roomForm.service" placeholder="Ex. : Médecine interne" maxlength="150" />
                </FormField>
                <FormField label="Chambre / lit" :error="roomForm.errors.room_bed">
                    <Input v-model="roomForm.room_bed" placeholder="Ex. : Chambre 3, lit B" maxlength="100" />
                </FormField>
                <div class="flex justify-end gap-2">
                    <Button type="button" size="sm" variant="ghost" @click="cancelRoom">Annuler</Button>
                    <Button type="submit" size="sm" :disabled="roomForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
                </div>
            </form>

            <!-- ADR-164 — sans lit configuré, la saisie libre continue ; l'écran dit pourquoi, et où les lits se créent. -->
            <p v-if="!bedsConfigured && isActive && !editingRoom" class="flex items-start gap-2 rounded-lg bg-muted/60 px-3 py-2.5 text-xs text-muted-foreground">
                <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>Les lits de ce site ne sont pas encore configurés : la chambre se note à la main. Ils se créent depuis le portail
                    Super Administration (« Services, chambres et lits ») ; « Attribuer un lit » apparaîtra alors ici.</span>
            </p>
        </div>

        <footer v-if="canMoveBed || canMoveFree || pastMovements.length" class="space-y-4 border-t border-border bg-muted/20 px-5 py-4">
            <Button v-if="canMoveBed" type="button" size="sm" variant="white-outline" class="w-full" @click="emit('bed', 'move')">
                <ArrowRightLeft class="h-4 w-4" />Changer de lit
            </Button>
            <Button v-else-if="canMoveFree" type="button" size="sm" variant="white-outline" class="w-full" @click="emit('move')">
                <ArrowRightLeft class="h-4 w-4" />Changer de service / lit
            </Button>

            <!-- ADR-161 — les emplacements précédents, jamais écrasés. -->
            <div v-if="pastMovements.length">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                    <History class="h-3.5 w-3.5" aria-hidden="true" />Emplacements précédents
                </p>
                <ol class="mt-2.5 space-y-2.5 border-s border-border ps-4">
                    <li v-for="movement in pastMovements" :key="movement.uuid" class="relative text-xs">
                        <span class="absolute -start-[1.3rem] top-1 h-2 w-2 rounded-full border border-border bg-card" aria-hidden="true" />
                        <p class="font-medium text-foreground">
                            {{ movement.service || 'Service non précisé' }}<template v-if="movement.room_bed"> · {{ movement.room_bed }}</template>
                        </p>
                        <p class="text-muted-foreground">
                            {{ movement.care_level_label }} · {{ formatDateTime(movement.started_at) }} → {{ formatDateTime(movement.ended_at) }}
                        </p>
                    </li>
                </ol>
            </div>
        </footer>
    </Card>
</template>
