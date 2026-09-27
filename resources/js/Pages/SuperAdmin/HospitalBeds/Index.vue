<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, BedDouble, Check, Pencil, Plus, RotateCcw, ShieldCheck, Wrench } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/utilities/date';
import { BED_STATE, careLevelVariant } from '@/utilities/hospitalBeds';

defineOptions({ layout: AppLayout });

/**
 * ADR-164 — services, chambres et lits de chaque site.
 *
 * Le portail règle le référentiel ; chaque écriture part vers l'API du site,
 * qui refuse ce qui laisserait un patient sans lit (un lit occupé ne se met
 * pas hors service et ne s'archive pas). L'occupation se lit sur les séjours
 * du site : le portail voit le passage et la date d'entrée, jamais le nom.
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ archived: false }) },
});

const { can } = usePermissions();
const canCreate = computed(() => can('hospital_beds.create'));
const canUpdate = computed(() => can('hospital_beds.update'));
const canArchive = computed(() => can('hospital_beds.archive'));
const canRestore = computed(() => can('hospital_beds.restore'));

const selectedCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const selected = computed(() => props.sites.find((site) => site.site.code === selectedCode.value));
const services = computed(() => selected.value?.data ?? []);
const summary = computed(() => selected.value?.meta?.summary ?? null);
const careLevels = computed(() => selected.value?.meta?.care_levels ?? []);
const maxBeds = computed(() => selected.value?.meta?.max_beds_per_room ?? 30);
const base = computed(() => `/super-admin/hospital-beds/${selectedCode.value}`);

const showArchived = ref(Boolean(props.filters.archived));
watch(showArchived, (value) => router.get('/super-admin/hospital-beds', value ? { archived: 1 } : {}, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
}));

const stats = computed(() => (summary.value ? [
    { key: 'services', label: 'Services', value: summary.value.services },
    { key: 'rooms', label: 'Chambres', value: summary.value.rooms },
    { key: 'beds', label: 'Lits', value: summary.value.beds },
    { key: 'free', label: 'Libres', value: summary.value.free, tone: 'text-emerald-600 dark:text-emerald-400' },
    { key: 'occupied', label: 'Occupés', value: summary.value.occupied, tone: 'text-sky-600 dark:text-sky-400' },
    { key: 'out', label: 'Hors service', value: summary.value.out_of_service, tone: 'text-muted-foreground' },
] : []));

const freeCount = (service) => service.rooms
    .filter((room) => !room.archived)
    .reduce((total, room) => total + room.beds.filter((bed) => !bed.archived && bed.state === 'FREE').length, 0);
const bedCount = (service) => service.rooms
    .filter((room) => !room.archived)
    .reduce((total, room) => total + room.beds.filter((bed) => !bed.archived).length, 0);

const options = { preserveScroll: true };

// ── Service : créer, renommer / changer le niveau de soins ─────────────────
const serviceDialog = ref(null); // null | { mode: 'create' } | { mode: 'edit', service }
const serviceForm = useForm({ name: '', care_level: 'STANDARD' });
const openService = (service = null) => {
    serviceForm.clearErrors();
    serviceForm.name = service?.name ?? '';
    serviceForm.care_level = service?.care_level ?? 'STANDARD';
    serviceDialog.value = service ? { mode: 'edit', service } : { mode: 'create' };
};
const submitService = () => {
    const done = { ...options, onSuccess: () => { serviceDialog.value = null; } };

    if (serviceDialog.value.mode === 'edit') {
        serviceForm.put(`${base.value}/services/${serviceDialog.value.service.uuid}`, done);
    } else {
        serviceForm.post(`${base.value}/services`, done);
    }
};

// ── Chambre : créer avec son nombre de lits, renommer, ajouter des lits ────
const roomDialog = ref(null); // null | { mode: 'create', service } | { mode: 'edit', room } | { mode: 'beds', room }
const roomForm = useForm({ name: '', bed_count: 2, count: 1 });
const openRoom = (mode, target) => {
    roomForm.clearErrors();
    roomForm.name = mode === 'edit' ? target.name : '';
    roomForm.bed_count = 2;
    roomForm.count = 1;
    roomDialog.value = { mode, target };
};
const bedPreview = computed(() => {
    const count = Math.min(Math.max(Number(roomForm.bed_count) || 0, 0), maxBeds.value);

    if (count === 0) return '';

    return count === 1 ? 'Lit 1' : `Lit 1 … Lit ${count}`;
});
const submitRoom = () => {
    const { mode, target } = roomDialog.value;
    const done = { ...options, onSuccess: () => { roomDialog.value = null; } };

    if (mode === 'create') {
        roomForm.transform((data) => ({ name: data.name, bed_count: Number(data.bed_count) }))
            .post(`${base.value}/services/${target.uuid}/rooms`, done);
    } else if (mode === 'edit') {
        roomForm.transform((data) => ({ name: data.name })).put(`${base.value}/rooms/${target.uuid}`, done);
    } else {
        roomForm.transform((data) => ({ count: Number(data.count) })).post(`${base.value}/rooms/${target.uuid}/beds`, done);
    }
};

// ── Lit : renommer, hors service, archiver ─────────────────────────────────
const bedDialog = ref(null); // null | { bed, room, service }
const bedForm = useForm({ label: '' });
const openBed = (bed, room, service) => {
    bedForm.clearErrors();
    bedForm.label = bed.label;
    bedDialog.value = { bed, room, service };
};
const submitBed = () => bedForm.put(`${base.value}/beds/${bedDialog.value.bed.uuid}`, {
    ...options,
    onSuccess: () => { bedDialog.value = null; },
});
const putBackInService = (bed) => router.post(`${base.value}/beds/${bed.uuid}/in-service`, {}, {
    ...options,
    onSuccess: () => { bedDialog.value = null; },
});

// ── Motif : archiver un service, une chambre ou un lit ; lit hors service ──
const reasonDialog = ref(null); // null | { title, description, confirm, method, url, variant }
const reasonForm = useForm({ reason: '' });
const askReason = (dialog) => {
    reasonForm.reset();
    reasonForm.clearErrors();
    bedDialog.value = null;
    reasonDialog.value = dialog;
};
const submitReason = () => {
    const done = { ...options, onSuccess: () => { reasonDialog.value = null; } };

    if (reasonDialog.value.method === 'delete') {
        reasonForm.delete(reasonDialog.value.url, done);
    } else {
        reasonForm.post(reasonDialog.value.url, done);
    }
};
const archiveService = (service) => askReason({
    title: `Archiver « ${service.name} »`,
    description: 'Ses chambres et ses lits ne seront plus proposés. Refusé tant qu’un patient occupe un de ses lits.',
    confirm: 'Archiver le service',
    method: 'delete',
    url: `${base.value}/services/${service.uuid}`,
});
const archiveRoom = (room) => askReason({
    title: `Archiver « ${room.name} »`,
    description: 'Ses lits ne seront plus proposés ; la restaurer les rend tels qu’ils étaient. Refusé tant qu’un patient y est installé.',
    confirm: 'Archiver la chambre',
    method: 'delete',
    url: `${base.value}/rooms/${room.uuid}`,
});
const archiveBed = (bed, room) => askReason({
    title: `Archiver ${room.name} · ${bed.label}`,
    description: 'Le lit quitte le référentiel ; les séjours qui l’ont occupé gardent son nom.',
    confirm: 'Archiver le lit',
    method: 'delete',
    url: `${base.value}/beds/${bed.uuid}`,
});
const outOfService = (bed, room) => askReason({
    title: `Mettre ${room.name} · ${bed.label} hors service`,
    description: 'Le lit reste dans le référentiel mais n’est plus proposé aux soignants, jusqu’à sa remise en service.',
    confirm: 'Mettre hors service',
    method: 'post',
    url: `${base.value}/beds/${bed.uuid}/out-of-service`,
});
const restore = (kind, item) => router.post(`${base.value}/${kind}/${item.uuid}/restore`, {}, options);

// Une restauration ou une remise en service part sans formulaire : son refus
// arrive par la page. Tant qu'une fenêtre est ouverte, c'est elle qui le dit.
const page = usePage();
const anyDialogOpen = computed(() => [serviceDialog, roomDialog, bedDialog, reasonDialog].some((dialog) => dialog.value !== null));
const generalError = computed(() => {
    if (anyDialogOpen.value) return null;
    const errors = page.props.errors ?? {};

    return errors.site ?? errors.service ?? errors.room ?? errors.bed ?? null;
});
</script>

<template>
    <Head title="Services, chambres & lits" />

    <div class="w-full space-y-5 pb-8">
        <header class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground shadow-sm">
                    <BedDouble class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Services, chambres &amp; lits</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Créez les services et leurs chambres avec le nombre de lits. Un lit occupé ne peut être attribué à un autre patient.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                    <Checkbox v-model="showArchived" aria-label="Afficher les éléments archivés" />Afficher les archivés
                </label>
                <span class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-xs text-muted-foreground">
                    <ShieldCheck class="h-4 w-4" />Écritures auditées sur le site destinataire
                </span>
            </div>
        </header>

        <Card class="overflow-hidden">
            <div class="flex gap-1 overflow-x-auto border-b border-border bg-muted/50 p-2" role="tablist" aria-label="Site">
                <button
                    v-for="site in sites"
                    :key="site.site.code"
                    type="button"
                    role="tab"
                    :aria-selected="selectedCode === site.site.code"
                    :class="[
                        'inline-flex min-w-40 items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                        selectedCode === site.site.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    ]"
                    @click="selectedCode = site.site.code"
                >
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selected?.ok" class="px-6 py-12 text-center" role="alert">
                <p class="text-sm font-semibold text-foreground">Ce site ne répond pas pour le moment.</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ selected?.message ?? 'Ses lits ne peuvent être ni lus ni modifiés.' }}</p>
            </div>

            <div v-else class="space-y-5 p-4 sm:p-5">
                <dl class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                    <div v-for="stat in stats" :key="stat.key" class="rounded-lg border border-border bg-card px-3 py-2">
                        <dt class="text-[11px] font-medium text-muted-foreground">{{ stat.label }}</dt>
                        <dd :class="['text-xl font-bold tabular-nums', stat.tone ?? 'text-foreground']">{{ stat.value }}</dd>
                    </div>
                </dl>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span v-for="state in ['FREE', 'OCCUPIED', 'OUT_OF_SERVICE']" :key="state" class="inline-flex items-center gap-1.5">
                            <span :class="['h-2.5 w-2.5 rounded-sm border', BED_STATE[state].swatch]" />{{ BED_STATE[state].label }}
                        </span>
                    </div>
                    <Button v-if="canCreate" type="button" size="sm" @click="openService()"><Plus class="h-4 w-4" />Nouveau service</Button>
                </div>

                <FormError :message="generalError" />

                <div v-if="!services.length" class="rounded-lg border border-dashed border-border px-6 py-10 text-center">
                    <BedDouble class="mx-auto h-8 w-8 text-muted-foreground" aria-hidden="true" />
                    <p class="mt-2 text-sm font-semibold text-foreground">Aucun service configuré sur {{ selected.site.name }}.</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Tant qu’aucun lit n’existe, les soignants saisissent la chambre à la main. Dès le premier lit, ils le choisissent dans la liste.
                    </p>
                    <Button v-if="canCreate" type="button" size="sm" class="mt-4" @click="openService()"><Plus class="h-4 w-4" />Créer le premier service</Button>
                </div>

                <section
                    v-for="service in services"
                    :key="service.uuid"
                    :class="['rounded-xl border border-border', service.archived ? 'bg-muted/40' : 'bg-card']"
                    :aria-label="`Service ${service.name}`"
                >
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-4 py-3">
                        <h2 class="text-base font-bold text-foreground">{{ service.name }}</h2>
                        <Badge :variant="careLevelVariant(service.care_level)">{{ service.care_level_label }}</Badge>
                        <Badge v-if="service.archived" variant="outline"><Archive class="h-3 w-3" />Archivé</Badge>
                        <span v-else class="text-xs text-muted-foreground">
                            {{ freeCount(service) }} libre{{ freeCount(service) > 1 ? 's' : '' }} sur {{ bedCount(service) }} lit{{ bedCount(service) > 1 ? 's' : '' }}
                        </span>
                        <span class="ms-auto flex flex-wrap gap-1.5">
                            <template v-if="!service.archived">
                                <Button v-if="canCreate" type="button" size="xs" variant="white-outline" @click="openRoom('create', service)"><Plus class="h-3.5 w-3.5" />Nouvelle chambre</Button>
                                <Button v-if="canUpdate" type="button" size="icon-xs" variant="ghost" :aria-label="`Modifier le service ${service.name}`" title="Modifier" @click="openService(service)"><Pencil class="h-3.5 w-3.5" /></Button>
                                <Button v-if="canArchive" type="button" size="icon-xs" variant="ghost" class="text-destructive" :aria-label="`Archiver le service ${service.name}`" title="Archiver" @click="archiveService(service)"><Archive class="h-3.5 w-3.5" /></Button>
                            </template>
                            <Button v-else-if="canRestore" type="button" size="xs" variant="white-outline" @click="restore('services', service)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                        </span>
                    </header>
                    <p v-if="service.archived && service.archive_reason" class="px-4 pt-2 text-xs text-muted-foreground">Motif : {{ service.archive_reason }}</p>

                    <p v-if="!service.rooms.length" class="px-4 py-4 text-sm text-muted-foreground">Aucune chambre dans ce service.</p>
                    <div v-else class="grid gap-3 p-4 md:grid-cols-2 2xl:grid-cols-3">
                        <div
                            v-for="room in service.rooms"
                            :key="room.uuid"
                            :class="['rounded-lg border border-border p-3', room.archived ? 'bg-muted/40 opacity-80' : 'bg-background']"
                        >
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-foreground">{{ room.name }}</h3>
                                <Badge v-if="room.archived" variant="outline">Archivée</Badge>
                                <span v-else class="text-xs text-muted-foreground">{{ room.beds.filter((bed) => !bed.archived).length }} lit{{ room.beds.filter((bed) => !bed.archived).length > 1 ? 's' : '' }}</span>
                                <span class="ms-auto flex gap-1">
                                    <template v-if="!room.archived && !service.archived">
                                        <Button v-if="canCreate" type="button" size="icon-xs" variant="ghost" :aria-label="`Ajouter des lits à ${room.name}`" title="Ajouter des lits" @click="openRoom('beds', room)"><Plus class="h-3.5 w-3.5" /></Button>
                                        <Button v-if="canUpdate" type="button" size="icon-xs" variant="ghost" :aria-label="`Renommer ${room.name}`" title="Renommer" @click="openRoom('edit', room)"><Pencil class="h-3.5 w-3.5" /></Button>
                                        <Button v-if="canArchive" type="button" size="icon-xs" variant="ghost" class="text-destructive" :aria-label="`Archiver ${room.name}`" title="Archiver" @click="archiveRoom(room)"><Archive class="h-3.5 w-3.5" /></Button>
                                    </template>
                                    <Button v-else-if="room.archived && !service.archived && canRestore" type="button" size="xs" variant="ghost" @click="restore('rooms', room)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                                </span>
                            </div>
                            <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                <li v-for="bed in room.beds" :key="bed.uuid">
                                    <button
                                        type="button"
                                        :disabled="room.archived || service.archived"
                                        :class="[
                                            'inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-default',
                                            bed.archived ? 'border-dashed border-border text-muted-foreground line-through' : BED_STATE[bed.state].chip,
                                        ]"
                                        :aria-label="`${room.name} · ${bed.label} — ${bed.archived ? 'archivé' : bed.state_label}`"
                                        :title="bed.occupant?.episode_number ? `Occupé · ${bed.occupant.episode_number}` : (bed.out_of_service_reason ?? bed.state_label)"
                                        @click="openBed(bed, room, service)"
                                    >
                                        <Wrench v-if="!bed.archived && bed.state === 'OUT_OF_SERVICE'" class="h-3 w-3" aria-hidden="true" />
                                        {{ bed.label }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>
        </Card>

        <Dialog
            :open="serviceDialog !== null"
            :title="serviceDialog?.mode === 'edit' ? 'Modifier le service' : `Nouveau service — ${selected?.site.name ?? ''}`"
            description="Le niveau de soins du service est donné au séjour du patient installé dans un de ses lits."
            size="md"
            @update:open="(value) => { if (!value) serviceDialog = null; }"
        >
            <form id="hospital-service" class="space-y-4" @submit.prevent="submitService">
                <FormField label="Nom du service" required :error="serviceForm.errors.name">
                    <Input v-model="serviceForm.name" maxlength="150" placeholder="Ex. : Médecine interne" />
                </FormField>
                <FormField as="div" label="Niveau de soins" required :error="serviceForm.errors.care_level">
                    <Select v-model="serviceForm.care_level" class="w-full" :options="careLevels" aria-label="Niveau de soins" />
                </FormField>
                <p v-if="serviceDialog?.mode === 'edit'" class="text-xs text-muted-foreground">
                    Changer le niveau vaut pour les prochaines installations : les patients déjà dans ses lits gardent le leur.
                </p>
                <FormError :message="serviceForm.errors.site ?? serviceForm.errors.service" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="serviceForm.processing" @click="serviceDialog = null">Annuler</Button>
                <Button type="submit" form="hospital-service" :disabled="serviceForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="roomDialog !== null"
            :title="roomDialog?.mode === 'create' ? `Nouvelle chambre — ${roomDialog?.target.name}` : roomDialog?.mode === 'edit' ? 'Renommer la chambre' : `Ajouter des lits — ${roomDialog?.target.name}`"
            :description="roomDialog?.mode === 'create' ? 'Les lits sont créés d’office et numérotés ; chacun se renomme ensuite.' : ''"
            size="md"
            @update:open="(value) => { if (!value) roomDialog = null; }"
        >
            <form id="hospital-room" class="space-y-4" @submit.prevent="submitRoom">
                <FormField v-if="roomDialog?.mode !== 'beds'" label="Nom de la chambre" required :error="roomForm.errors.name">
                    <Input v-model="roomForm.name" maxlength="100" placeholder="Ex. : Chambre 12" />
                </FormField>
                <FormField v-if="roomDialog?.mode === 'create'" label="Nombre de lits" required :error="roomForm.errors.bed_count">
                    <Input v-model="roomForm.bed_count" type="number" min="1" :max="maxBeds" inputmode="numeric" />
                </FormField>
                <p v-if="roomDialog?.mode === 'create' && bedPreview" class="text-xs text-muted-foreground">Lits créés : {{ bedPreview }}</p>
                <FormField v-if="roomDialog?.mode === 'beds'" label="Lits à ajouter" required :error="roomForm.errors.count">
                    <Input v-model="roomForm.count" type="number" min="1" :max="maxBeds" inputmode="numeric" />
                </FormField>
                <p v-if="roomDialog?.mode === 'beds'" class="text-xs text-muted-foreground">Ils reçoivent les numéros suivants de la chambre.</p>
                <FormError :message="roomForm.errors.site ?? roomForm.errors.room" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="roomForm.processing" @click="roomDialog = null">Annuler</Button>
                <Button type="submit" form="hospital-room" :disabled="roomForm.processing"><Check class="h-4 w-4" />Enregistrer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="bedDialog !== null"
            :title="bedDialog ? `${bedDialog.room.name} · ${bedDialog.bed.label}` : 'Lit'"
            :description="bedDialog ? bedDialog.service.name : ''"
            size="md"
            @update:open="(value) => { if (!value) bedDialog = null; }"
        >
            <div v-if="bedDialog" class="space-y-4">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <Badge v-if="bedDialog.bed.archived" variant="outline">Archivé</Badge>
                    <Badge v-else :variant="BED_STATE[bedDialog.bed.state].badge">{{ bedDialog.bed.state_label }}</Badge>
                    <span v-if="bedDialog.bed.occupant" class="text-muted-foreground">
                        Passage {{ bedDialog.bed.occupant.episode_number }} · depuis le {{ formatDateTime(bedDialog.bed.occupant.admitted_at) }}
                    </span>
                </div>
                <p v-if="bedDialog.bed.state === 'OUT_OF_SERVICE' && !bedDialog.bed.archived" class="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    Hors service depuis le {{ formatDateTime(bedDialog.bed.out_of_service_at) }}<template v-if="bedDialog.bed.out_of_service_by"> par {{ bedDialog.bed.out_of_service_by }}</template> : {{ bedDialog.bed.out_of_service_reason }}
                </p>
                <p v-if="bedDialog.bed.state === 'OCCUPIED'" class="text-xs text-muted-foreground">
                    Un lit occupé ne se met pas hors service et ne s’archive pas : le patient change d’abord de lit, ou sort.
                </p>
                <form v-if="canUpdate && !bedDialog.bed.archived" id="hospital-bed" class="flex items-end gap-2" @submit.prevent="submitBed">
                    <FormField label="Nom du lit" class="flex-1" :error="bedForm.errors.label ?? bedForm.errors.bed">
                        <Input v-model="bedForm.label" maxlength="50" />
                    </FormField>
                    <Button type="submit" size="sm" variant="white-outline" :disabled="bedForm.processing || bedForm.label === bedDialog.bed.label"><Check class="h-4 w-4" />Renommer</Button>
                </form>
            </div>
            <template #footer>
                <template v-if="bedDialog && !bedDialog.bed.archived">
                    <Button v-if="canArchive && bedDialog.bed.state !== 'OCCUPIED'" type="button" variant="danger-outline" @click="archiveBed(bedDialog.bed, bedDialog.room)"><Archive class="h-4 w-4" />Archiver</Button>
                    <Button v-if="canUpdate && bedDialog.bed.state === 'FREE'" type="button" variant="white-outline" @click="outOfService(bedDialog.bed, bedDialog.room)"><Wrench class="h-4 w-4" />Mettre hors service</Button>
                    <Button v-if="canUpdate && bedDialog.bed.state === 'OUT_OF_SERVICE'" type="button" @click="putBackInService(bedDialog.bed)"><Check class="h-4 w-4" />Remettre en service</Button>
                </template>
                <Button v-else-if="bedDialog && canRestore" type="button" @click="restore('beds', bedDialog.bed); bedDialog = null"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                <Button type="button" variant="ghost" @click="bedDialog = null">Fermer</Button>
            </template>
        </Dialog>

        <Dialog
            :open="reasonDialog !== null"
            :title="reasonDialog?.title ?? ''"
            :description="reasonDialog?.description ?? ''"
            size="md"
            :dismissible="false"
            @update:open="(value) => { if (!value) reasonDialog = null; }"
        >
            <form id="hospital-reason" class="space-y-3" @submit.prevent="submitReason">
                <FormField label="Motif" required :error="reasonForm.errors.reason">
                    <Textarea v-model="reasonForm.reason" :rows="2" maxlength="500" placeholder="Ex. : sommier cassé, travaux dans la chambre" />
                </FormField>
                <FormError :message="reasonForm.errors.site ?? reasonForm.errors.service ?? reasonForm.errors.room ?? reasonForm.errors.bed" />
            </form>
            <template #footer>
                <Button type="button" variant="white-outline" :disabled="reasonForm.processing" @click="reasonDialog = null">Annuler</Button>
                <Button type="submit" form="hospital-reason" variant="destructive" :disabled="reasonForm.processing || reasonForm.reason.trim().length < 5">{{ reasonDialog?.confirm }}</Button>
            </template>
        </Dialog>
    </div>
</template>
