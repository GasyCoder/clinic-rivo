<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Baby, CalendarDays, CalendarHeart, CircleHelp, HeartPulse, Layers, Save, Scissors, Sparkles, Stethoscope } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivePassageBoard from '@/Components/Clinical/ActivePassageBoard.vue';
import SoinsTabs from '@/Components/Care/SoinsTabs.vue';
import SoinsWorkspaceHeader from '@/Components/Care/SoinsWorkspaceHeader.vue';
import MaternityEncounterChooser from '@/Components/Maternity/MaternityEncounterChooser.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import { formatDate, formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * ADR-177 — la Maternité lit le même tableau des passages que les Soins et la
 * Médecine. Ce qui lui est propre se lit sur chaque ligne : où est allée la
 * patiente ensuite — le médecin, la césarienne (ADR-135).
 *
 * ADR-204 — deux parcours, filtrés par onglet : les consultations prénatales
 * et les accouchements. L'onglet ne change ni la visibilité d'un passage ni
 * son n° de file ; prendre en charge demande de choisir le parcours, que la
 * suggestion de la Réception peut présélectionner — jamais décider.
 */
const props = defineProps({
    passages: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
    /** `all` | `PRENATAL` | `DELIVERY` */
    type: { type: String, default: 'all' },
    typeCounts: { type: Object, default: () => ({}) },
    encounterOptions: { type: Array, default: () => [] },
    /**
     * Par UUID de passage : le parcours du dossier (choisi, ou déduit pour un dossier
     * d'avant ce choix — `inferred`), sinon la suggestion de la Réception, et le
     * dernier enregistrement du dossier.
     */
    encounters: { type: Object, default: () => ({}) },
    /** Par UUID de passage : `{ medicine, cesarean }`. */
    followUps: { type: Object, default: () => ({}) },
    /** Grossesse liée au passage, ou grossesse active proposée pour la patiente. */
    pregnancyContexts: { type: Object, default: () => ({}) },
});

/** Le ton du statut, pas ses classes : le `Badge` porte déjà le vocabulaire. */
const medicineTone = (medicine) => ({ PENDING: 'warning', IN_PROGRESS: 'info' }[medicine.status] ?? 'success');

const TYPE_TABS = [
    { value: 'all', label: 'Tous', icon: Layers },
    { value: 'PRENATAL', label: 'Consultations', icon: CalendarHeart },
    { value: 'DELIVERY', label: 'Accouchements', icon: Baby },
];
/**
 * Les comptes des onglets sont ceux du bloc ouvert (« En attente », « En cours
 * chez moi »…) : sans le dire, « Tous · 2 » se lit comme le total de la page.
 */
const VIEW_SCOPES = {
    waiting: 'en attente',
    suggested: 'suggérés, en attente',
    in_progress: 'en cours chez moi',
    completed: 'terminés chez moi',
    emergency: 'en urgence',
};
const viewScope = computed(() => VIEW_SCOPES[props.view] ?? VIEW_SCOPES.waiting);
const typeCount = (value) => props.typeCounts[value] ?? 0;
const typeTitle = (tab) => {
    const count = typeCount(tab.value);

    return `${count} passage${count > 1 ? 's' : ''} ${viewScope.value}${tab.value === 'all' ? '' : ` — ${tab.label.toLowerCase()}`}`;
};
const extraParams = computed(() => (props.type && props.type !== 'all' ? { type: props.type } : {}));
const selectType = (type) => router.get('/maternity', {
    ...(props.view && props.view !== 'waiting' ? { view: props.view } : {}),
    ...(props.search ? { q: props.search } : {}),
    ...(type !== 'all' ? { type } : {}),
}, { preserveState: true, preserveScroll: true, replace: true });

// ── Prise en charge : le parcours se choisit, la suggestion ne fait que présélectionner ──
const choosing = ref(null);
const starting = ref(null);
const requestTakeCharge = ({ row, proceed }) => { choosing.value = { row, proceed }; starting.value = null; };
const startWith = (type) => {
    if (! choosing.value || starting.value) return;

    starting.value = type;
    choosing.value.proceed({ encounter_type: type });
};
const choosingEncounter = computed(() => (choosing.value ? props.encounters[choosing.value.row.uuid] : null));
const ENCOUNTER_ICONS = { PRENATAL: CalendarHeart, DELIVERY: Baby };

/** DPA et dernière consultation, au survol : la ligne n'en garde que l'essentiel. */
const pregnancyTitle = (pregnancy) => [
    `Grossesse ${pregnancy.reference}`,
    `DPA ${formatDate(pregnancy.estimated_due_date) ?? 'non renseignée'}`,
    `dernière consultation ${formatDateTime(pregnancy.last_consultation_at) ?? 'aucune'}`,
].join(' · ');
/** Le dernier enregistrement du dossier, seulement pendant la prise en charge : c'est là qu'il rassure. */
const showsSave = (row) => row.module.state === 'IN_PROGRESS' && Boolean(props.encounters[row.uuid]?.record_updated_at);
</script>

<template>
    <Head title="Maternité" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <SoinsTabs current="maternity" />

        <SoinsWorkspaceHeader
            :icon="HeartPulse"
            tone="rose"
            eyebrow="Workspace paramédical spécialisé"
            title="Maternité"
            description="Patientes en attente par ordre d’arrivée, puis celles prises en charge, puis celles déjà terminées."
        >
            <!-- Le parcours filtre toute la page — cartes et liste : il se lit avec le titre,
                 pas comme une seconde barre d'onglets empilée sur les cartes. -->
            <div class="flex w-full flex-col gap-1.5 lg:w-auto lg:items-end">
                <p id="maternity-type-label" class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Parcours <span class="font-medium normal-case tracking-normal">· {{ viewScope }}</span>
                </p>
                <Tabs :model-value="type" @update:model-value="selectType">
                    <TabsList class="h-auto w-full flex-wrap justify-start gap-0.5 sm:w-auto" aria-labelledby="maternity-type-label">
                        <TabsTrigger
                            v-for="tab in TYPE_TABS"
                            :key="tab.value"
                            :value="tab.value"
                            :title="typeTitle(tab)"
                            class="min-h-8 grow px-2 text-xs sm:grow-0 sm:px-2.5 sm:text-sm"
                        >
                            <component :is="tab.icon" class="hidden h-4 w-4 sm:block" aria-hidden="true" />{{ tab.label }}
                            <span
                                :class="cn(
                                    'min-w-5 rounded-full px-1.5 text-center text-[11px] font-bold leading-5 tabular-nums',
                                    type === tab.value
                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                        : 'bg-background/80 text-muted-foreground',
                                )"
                            >{{ typeCounts[tab.value] ?? 0 }}<span class="sr-only"> {{ viewScope }}</span></span>
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>
        </SoinsWorkspaceHeader>

        <ActivePassageBoard
            module="MATERNITY"
            base-url="/maternity"
            :passages="passages"
            :counts="counts"
            :view="view"
            :search="search"
            :extra-params="extraParams"
            intercept-take-charge
            @take-charge="requestTakeCharge"
        >
            <!-- Sous le nom : le parcours et la grossesse, en deux lignes — ce qu'on cherche
                 d'abord en Maternité, sans un grand cadre qui repousse la ligne. -->
            <template #patient-details="{ row }">
                <div v-if="encounters[row.uuid]?.type || encounters[row.uuid]?.suggested || ! row.module.is_waiting" class="mt-1.5 flex flex-wrap items-center gap-1">
                    <Badge
                        v-if="encounters[row.uuid]?.type"
                        :tone="encounters[row.uuid].type === 'DELIVERY' ? 'info' : 'success'"
                        class="px-2 py-0.5 text-[11px]"
                        :title="encounters[row.uuid].inferred ? 'Dossier antérieur au choix du parcours : déduit de son contenu' : `Parcours : ${encounters[row.uuid].type_label}`"
                    >
                        <component :is="ENCOUNTER_ICONS[encounters[row.uuid].type]" class="h-3 w-3" aria-hidden="true" />{{ encounters[row.uuid].type_label }}
                    </Badge>
                    <Badge v-else-if="encounters[row.uuid]?.suggested" variant="outline" class="border-dashed px-2 py-0.5 text-[11px]" title="Suggéré par la Réception — le parcours se choisit à la prise en charge">
                        <Sparkles class="h-3 w-3" aria-hidden="true" />{{ encounters[row.uuid].suggested_label }} ?
                    </Badge>
                    <Badge v-else variant="outline" class="border-dashed px-2 py-0.5 text-[11px] text-muted-foreground" title="Le parcours se choisit en ouvrant le dossier">
                        <CircleHelp class="h-3 w-3" aria-hidden="true" />Parcours à choisir
                    </Badge>
                </div>
                <!-- La référence ne se coupe jamais ; le terme passe à la ligne s'il manque de place. -->
                <p
                    v-if="pregnancyContexts[row.uuid]"
                    class="mt-1 flex items-start gap-1 text-[11px] text-rose-700 dark:text-rose-300"
                    :title="pregnancyTitle(pregnancyContexts[row.uuid])"
                >
                    <CalendarDays class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="whitespace-nowrap font-semibold">{{ pregnancyContexts[row.uuid].reference }}</span>
                        · <span class="whitespace-nowrap">{{ pregnancyContexts[row.uuid].gestational_age_label ?? 'terme non calculable' }}</span>
                    </span>
                </p>
                <p v-if="pregnancyContexts[row.uuid]?.estimated_due_date" class="ps-[18px] text-[11px] text-muted-foreground">DPA {{ formatDate(pregnancyContexts[row.uuid].estimated_due_date) }}</p>
            </template>

            <!-- Là où se lit la prise en charge : ce qui l'a suivie, et le dernier enregistrement. -->
            <template #row-details="{ row }">
                <div v-if="followUps[row.uuid]?.medicine || followUps[row.uuid]?.cesarean || showsSave(row)" class="mt-1.5 flex max-w-[260px] flex-wrap gap-1">
                    <Badge v-if="followUps[row.uuid]?.medicine" :tone="medicineTone(followUps[row.uuid].medicine)" class="px-2 py-0.5 text-[11px]">
                        <Stethoscope class="h-3 w-3" aria-hidden="true" />{{ followUps[row.uuid].medicine.label }}
                    </Badge>
                    <Badge v-if="followUps[row.uuid]?.cesarean" tone="warning" class="px-2 py-0.5 text-[11px]">
                        <Scissors class="h-3 w-3" aria-hidden="true" />{{ followUps[row.uuid].cesarean.label }}
                    </Badge>
                    <span v-if="followUps[row.uuid]?.medicine?.doctor" class="w-full text-[11px] text-muted-foreground">Médecin : {{ followUps[row.uuid].medicine.doctor }}</span>
                    <span v-if="showsSave(row)" class="flex w-full items-center gap-1 text-[11px] text-emerald-700 dark:text-emerald-400">
                        <Save class="h-3 w-3" aria-hidden="true" />Dossier enregistré {{ formatDateTime(encounters[row.uuid].record_updated_at) }}
                    </span>
                </div>
            </template>
        </ActivePassageBoard>

        <Dialog
            :open="choosing !== null"
            title="Commencer la prise en charge"
            :description="choosing ? `${formatPatientName(choosing.row.episode?.patient)} · passage ${choosing.row.episode?.episode_number ?? ''}` : ''"
            size="lg"
            @update:open="(open) => { if (! open && ! starting) choosing = null; }"
        >
            <MaternityEncounterChooser
                :options="encounterOptions"
                :suggested="choosingEncounter?.type ?? choosingEncounter?.suggested ?? null"
                :processing="starting"
                title="Quel parcours commencez-vous ?"
                description="La suggestion de la Réception n’est qu’une indication. Le parcours se change ensuite depuis le dossier, sans rien effacer."
                @choose="startWith"
            />
        </Dialog>
    </div>
</template>
