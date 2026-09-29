<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ArrowDown, ArrowUp, Check, CircleDollarSign, Eye, GripVertical,
    LayoutGrid, List, Pencil, Plus, RotateCcw, Search, Server, ToggleLeft,
    ToggleRight, UserRound, WalletCards,
} from 'lucide-vue-next';
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
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utilities/money';
import { mergeVisibleCardOrder, moveCard, normalizeCardOrder } from '@/utilities/sortableCards';

defineOptions({ layout: AppLayout });

const props = defineProps({ sites: Array, filters: Object });
const { can } = usePermissions();
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code ?? '');
const showCreate = ref(false);
const filterStatus = ref(props.filters.status ?? 'ACTIVE');
const editing = ref(null);
const archiving = ref(null);
const configuringMethods = ref(null);
const viewMode = ref('grid');
const registerOrders = ref({});
const draggingUuid = ref(null);
const movedUuid = ref(null);

const VIEW_STORAGE_KEY = 'rivo.super-admin.cash-registers.view';
const orderStorageKey = (siteCode) => `rivo.super-admin.cash-registers.order.${siteCode}`;

const COLORS = ['#2563EB', '#0F766E', '#16A34A', '#D97706', '#DC2626', '#7C3AED', '#DB2777', '#475569'];
const blankRegister = () => ({ name: '', color: COLORS[0], opening_fund_amount: '0', assigned_user_uuid: '' });
const createForm = useForm({ site_code: selectedSiteCode.value, ...blankRegister() });
const editForm = useForm(blankRegister());
const archiveForm = useForm({ reason: '' });
const methodsForm = useForm({ payment_method_uuids: [] });

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const registers = computed(() => selectedSite.value?.data ?? []);
const orderedRegisters = computed(() => {
    const identifiers = registers.value.map((register) => register.uuid);
    const order = normalizeCardOrder(registerOrders.value[selectedSiteCode.value], identifiers);

    return order.map((uuid) => registers.value.find((register) => register.uuid === uuid)).filter(Boolean);
});
const siteMethods = computed(() => selectedSite.value?.meta?.payment_methods ?? []);
const eligibleUsers = computed(() => selectedSite.value?.meta?.eligible_users ?? []);
const userOptions = computed(() => [
    { value: '', label: 'Sans titulaire — toute personne autorisée' },
    ...eligibleUsers.value.map((user) => ({ value: user.uuid, label: user.name })),
]);
const editUserOptions = computed(() => {
    const options = [...userOptions.value];
    const holder = editing.value?.assigned_user;

    if (holder && !options.some((option) => option.value === holder.uuid)) {
        options.push({ value: holder.uuid, label: `${holder.name} — compte actuellement indisponible` });
    }

    return options;
});
const siteOptions = computed(() => props.sites.map((site) => ({
    value: site.site.code,
    label: site.ok ? site.site.name : `${site.site.name} — indisponible`,
    disabled: !site.ok,
})));
const statusOptions = [
    { value: 'ACTIVE', label: 'Caisses actives' },
    { value: 'ARCHIVED', label: 'Caisses archivées' },
    { value: 'ALL', label: 'Toutes les caisses' },
];

onMounted(() => {
    try {
        viewMode.value = localStorage.getItem(VIEW_STORAGE_KEY) === 'list' ? 'list' : 'grid';
        registerOrders.value = Object.fromEntries(props.sites.map((site) => {
            try {
                return [site.site.code, JSON.parse(localStorage.getItem(orderStorageKey(site.site.code)))];
            } catch {
                return [site.site.code, []];
            }
        }));
    } catch {
        // Private browsing or disabled storage: controls still work for this visit.
    }
});

const setViewMode = (mode) => {
    viewMode.value = mode === 'list' ? 'list' : 'grid';
    try { localStorage.setItem(VIEW_STORAGE_KEY, viewMode.value); } catch { /* preference remains in memory */ }
};

const persistOrder = () => {
    try {
        localStorage.setItem(orderStorageKey(selectedSiteCode.value), JSON.stringify(registerOrders.value[selectedSiteCode.value] ?? []));
    } catch { /* preference remains in memory */ }
};

const applyVisibleOrder = (visibleOrder, persist = false) => {
    registerOrders.value = {
        ...registerOrders.value,
        [selectedSiteCode.value]: mergeVisibleCardOrder(
            registerOrders.value[selectedSiteCode.value],
            visibleOrder,
        ),
    };

    if (persist) persistOrder();
};

const moveRegister = (register, delta) => {
    const current = orderedRegisters.value.map((item) => item.uuid);
    const next = moveCard(current, register.uuid, current.indexOf(register.uuid) + delta);

    if (next.join() === current.join()) return;

    applyVisibleOrder(next, true);
    movedUuid.value = register.uuid;
    window.setTimeout(() => {
        if (movedUuid.value === register.uuid) movedUuid.value = null;
    }, 800);
};

const onDragStart = (event, register) => {
    draggingUuid.value = register.uuid;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', register.uuid);
};

const onDragOver = (event, target) => {
    if (!draggingUuid.value || draggingUuid.value === target.uuid) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    const current = orderedRegisters.value.map((register) => register.uuid);
    applyVisibleOrder(moveCard(current, draggingUuid.value, current.indexOf(target.uuid)));
};

const onDragEnd = () => {
    if (!draggingUuid.value) return;

    movedUuid.value = draggingUuid.value;
    draggingUuid.value = null;
    persistOrder();
    window.setTimeout(() => { movedUuid.value = null; }, 800);
};

const resetOrder = () => {
    registerOrders.value = { ...registerOrders.value, [selectedSiteCode.value]: [] };
    try { localStorage.removeItem(orderStorageKey(selectedSiteCode.value)); } catch { /* already reset in memory */ }
};

const isCustomOrder = computed(() => orderedRegisters.value
    .some((register, index) => register.uuid !== registers.value[index]?.uuid));
const registerPosition = (register) => orderedRegisters.value.findIndex((item) => item.uuid === register.uuid);

const selectSite = (code) => {
    selectedSiteCode.value = code;
    createForm.site_code = code;
    showCreate.value = false;
    editing.value = null;
    configuringMethods.value = null;
};

const setCreateOpen = (open) => {
    showCreate.value = open;
    if (open) {
        createForm.clearErrors();
        Object.assign(createForm, { site_code: selectedSiteCode.value, ...blankRegister() });
    }
};

const submitCreate = () => createForm.post('/super-admin/cash-registers', {
    preserveScroll: true,
    onSuccess: () => { showCreate.value = false; },
});

const startEdit = (register) => {
    editForm.clearErrors();
    Object.assign(editForm, {
        name: register.name,
        color: register.color ?? COLORS[0],
        opening_fund_amount: register.opening_fund_amount ?? '',
        assigned_user_uuid: register.assigned_user?.uuid ?? '',
    });
    editing.value = register;
};

const submitEdit = () => editForm.put(`/super-admin/cash-registers/${selectedSiteCode.value}/${editing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; },
});

const openMethods = (register) => {
    methodsForm.clearErrors();
    methodsForm.payment_method_uuids = (register.accepted_payment_methods ?? []).map((method) => method.uuid);
    configuringMethods.value = register;
};
const toggleMethod = (uuid, checked) => {
    methodsForm.payment_method_uuids = checked
        ? [...new Set([...methodsForm.payment_method_uuids, uuid])]
        : methodsForm.payment_method_uuids.filter((value) => value !== uuid);
};
const submitMethods = () => methodsForm.put(
    `/super-admin/cash-registers/${selectedSiteCode.value}/${configuringMethods.value.uuid}/payment-methods`,
    { preserveScroll: true, onSuccess: () => { configuringMethods.value = null; } },
);

const openArchive = (register) => {
    archiving.value = register;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const submitArchive = () => archiveForm.delete(`/super-admin/cash-registers/${selectedSiteCode.value}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; archiveForm.reset(); },
});
const restore = (register) => router.post(`/super-admin/cash-registers/${selectedSiteCode.value}/${register.uuid}/restore`, {}, { preserveScroll: true });
const toggleActive = (register) => router.post(
    `/super-admin/cash-registers/${selectedSiteCode.value}/${register.uuid}/${register.active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);
const applyFilters = (event) => {
    const data = new FormData(event.currentTarget);
    router.get('/super-admin/cash-registers', { search: data.get('search'), status: filterStatus.value }, { preserveState: true, replace: true });
};

const acceptedMethodsLabel = (register) => register.accepted_payment_methods?.length
    ? register.accepted_payment_methods.map((method) => method.name).join(', ')
    : 'Tous les modes actifs';
</script>

<template>
    <Head title="Caisses" />

    <div class="mx-auto w-full max-w-[1540px] space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15"><WalletCards class="h-5 w-5" /></span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Super Administration</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-foreground">Postes de caisse</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">Attribuez chaque caisse, fixez son fond initial et suivez son activité sans quitter le portail central.</p>
                </div>
            </div>
            <Button v-if="can('cash_registers.create')" type="button" :disabled="!selectedSite?.ok" @click="setCreateOpen(true)"><Plus class="h-4 w-4" />Nouvelle caisse</Button>
        </header>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-muted p-1">
                    <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition-colors', selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']" @click="selectSite(site.site.code)">
                        <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : 'bg-red-500']" />
                        {{ site.site.name }}
                        <Badge v-if="site.ok" variant="outline" class="px-1.5 py-0 text-[10px]">{{ site.meta?.summary?.displayed ?? 0 }}</Badge>
                    </button>
                </div>
                <form class="grid gap-2 sm:grid-cols-[minmax(220px,320px)_190px_auto]" @submit.prevent="applyFilters">
                    <label class="relative"><Search class="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-muted-foreground" /><Input name="search" :model-value="filters.search ?? ''" type="search" class="ps-9" placeholder="Rechercher une caisse" /></label>
                    <Select v-model="filterStatus" :options="statusOptions" />
                    <Button variant="outline" type="submit">Filtrer</Button>
                </form>
            </div>

            <div v-if="selectedSite?.ok && registers.length" class="flex flex-wrap items-center gap-3 border-b border-border bg-muted/10 px-4 py-2.5">
                <p class="flex min-w-0 flex-1 items-center gap-2 text-xs text-muted-foreground">
                    <GripVertical class="h-4 w-4 shrink-0" />
                    <span><strong class="font-semibold text-foreground">{{ registers.length }} caisse{{ registers.length > 1 ? 's' : '' }}</strong><template v-if="registers.length > 1"> · Glissez la poignée ou utilisez les flèches pour modifier l’ordre.</template></span>
                </p>
                <Button v-if="isCustomOrder" type="button" size="sm" variant="ghost" title="Rétablir l’ordre reçu du site" @click="resetOrder"><RotateCcw class="h-3.5 w-3.5" />Réinitialiser l’ordre</Button>
                <div class="inline-flex shrink-0 rounded-lg bg-muted p-1" role="group" aria-label="Mode d’affichage des caisses">
                    <button type="button" :aria-pressed="viewMode === 'grid'" :class="['inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-bold transition-colors', viewMode === 'grid' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground']" @click="setViewMode('grid')"><LayoutGrid class="h-4 w-4" />Grille</button>
                    <button type="button" :aria-pressed="viewMode === 'list'" :class="['inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-bold transition-colors', viewMode === 'list' ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground']" @click="setViewMode('list')"><List class="h-4 w-4" />Liste</button>
                </div>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
                <h2 class="mt-3 text-sm font-bold text-foreground">{{ selectedSite?.site.name }} est indisponible</h2>
                <p class="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
            </div>

            <div v-else-if="registers.length" :class="['grid bg-muted/15 p-4', viewMode === 'grid' ? 'gap-4 md:grid-cols-2 2xl:grid-cols-3' : 'gap-2']">
                <article
                    v-for="register in orderedRegisters"
                    :key="register.uuid"
                    :class="[
                        'group relative flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm transition',
                        viewMode === 'grid' ? 'hover:-translate-y-0.5 hover:shadow-md' : 'lg:grid lg:grid-cols-[minmax(0,1fr)_auto] hover:border-primary/30',
                        draggingUuid === register.uuid ? 'scale-[0.99] opacity-45' : '',
                        movedUuid === register.uuid ? 'ring-2 ring-primary/35' : '',
                    ]"
                    @dragover="onDragOver($event, register)"
                    @drop.prevent
                >
                    <div class="absolute inset-x-0 top-0 h-1.5" :style="{ backgroundColor: register.color ?? COLORS[0] }" />
                    <div :class="[viewMode === 'grid' ? 'flex-1 p-5 pt-6' : 'grid gap-4 p-4 pt-5 lg:grid-cols-[minmax(240px,0.9fr)_minmax(460px,1.6fr)] lg:items-center']">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link :href="`/super-admin/cash-registers/${selectedSiteCode}/${register.uuid}`" class="truncate font-heading text-base font-bold text-foreground hover:text-primary">{{ register.name }}</Link>
                                    <Badge v-if="register.archived" variant="outline">Archivée</Badge>
                                    <Badge v-else-if="register.active" variant="success">Active</Badge>
                                    <Badge v-else variant="warning">Inactive</Badge>
                                </div>
                                <p v-if="register.session" class="mt-2 text-xs font-medium text-foreground">{{ register.session.status === 'LOCKED' ? 'Session verrouillée' : 'Session ouverte' }} · {{ register.session.session_number }}</p>
                                <p v-else class="mt-2 text-xs text-muted-foreground">Aucune session active</p>
                                <p v-if="register.session" class="mt-1 text-[11px] text-muted-foreground">Ouverte par <strong class="text-foreground">{{ register.session.opened_by }}</strong></p>
                                <p v-if="register.archived && register.archive_reason" class="mt-2 line-clamp-2 text-xs text-muted-foreground">{{ register.archive_reason }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-0.5">
                                <Button icon size="xs" variant="ghost" :disabled="registerPosition(register) === 0" :title="`Déplacer ${register.name} avant`" :aria-label="`Déplacer ${register.name} avant`" @click="moveRegister(register, -1)"><ArrowUp class="h-3.5 w-3.5" /></Button>
                                <span
                                    class="grid h-8 w-8 cursor-grab place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground active:cursor-grabbing"
                                    draggable="true"
                                    :title="`Déplacer ${register.name}`"
                                    @dragstart="onDragStart($event, register)"
                                    @dragend="onDragEnd"
                                ><GripVertical class="h-4 w-4" /></span>
                                <Button icon size="xs" variant="ghost" :disabled="registerPosition(register) === orderedRegisters.length - 1" :title="`Déplacer ${register.name} après`" :aria-label="`Déplacer ${register.name} après`" @click="moveRegister(register, 1)"><ArrowDown class="h-3.5 w-3.5" /></Button>
                                <span class="ms-1 grid h-9 w-9 place-items-center rounded-xl bg-muted" :style="{ color: register.color ?? COLORS[0] }"><WalletCards class="h-4 w-4" /></span>
                            </div>
                        </div>

                        <dl :class="['grid text-xs', viewMode === 'grid' ? 'mt-5 grid-cols-2 gap-3' : 'gap-2 sm:grid-cols-3']">
                            <div class="rounded-lg bg-muted/60 p-3"><dt class="flex items-center gap-1.5 text-muted-foreground"><UserRound class="h-3.5 w-3.5" />Titulaire</dt><dd class="mt-1 truncate font-bold text-foreground">{{ register.assigned_user?.name ?? 'Non attribuée' }}</dd></div>
                            <div class="rounded-lg bg-muted/60 p-3"><dt class="flex items-center gap-1.5 text-muted-foreground"><CircleDollarSign class="h-3.5 w-3.5" />Fond fixé</dt><dd class="mt-1 font-bold text-foreground">{{ register.opening_fund_amount === null ? 'Libre' : formatMoney(register.opening_fund_amount) }}</dd></div>
                            <div :class="['rounded-lg bg-muted/60 p-3', viewMode === 'grid' ? 'col-span-2' : '']"><dt class="flex items-center gap-1.5 text-muted-foreground"><LayoutGrid class="h-3.5 w-3.5" />Modes acceptés</dt><dd class="mt-1 truncate font-bold text-foreground">{{ acceptedMethodsLabel(register) }}</dd></div>
                        </dl>
                    </div>

                    <footer :class="['flex items-center justify-between border-t border-border bg-muted/20 px-4 py-3', viewMode === 'list' ? 'lg:flex-col lg:justify-center lg:border-s lg:border-t-0' : '']">
                        <span class="text-xs text-muted-foreground">{{ register.sessions_count }} session{{ register.sessions_count > 1 ? 's' : '' }}</span>
                        <div class="flex items-center gap-1">
                            <Button :as="Link" :href="`/super-admin/cash-registers/${selectedSiteCode}/${register.uuid}`" icon size="sm" variant="ghost" title="Voir les détails"><Eye class="h-4 w-4" /></Button>
                            <template v-if="!register.archived">
                                <Button v-if="can('cash_registers.update')" icon size="sm" variant="ghost" title="Modifier la caisse" @click="startEdit(register)"><Pencil class="h-4 w-4" /></Button>
                                <Button v-if="can('cash_registers.update')" icon size="sm" variant="ghost" title="Configurer les modes de paiement" @click="openMethods(register)"><LayoutGrid class="h-4 w-4" /></Button>
                                <Button v-if="register.active ? can('cash_registers.deactivate') : can('cash_registers.activate')" icon size="sm" variant="ghost" :title="register.active ? 'Désactiver' : 'Activer'" @click="toggleActive(register)"><component :is="register.active ? ToggleLeft : ToggleRight" class="h-4 w-4" /></Button>
                                <Button v-if="can('cash_registers.archive')" icon size="sm" variant="ghost" class="text-destructive" title="Archiver" @click="openArchive(register)"><Archive class="h-4 w-4" /></Button>
                            </template>
                            <Button v-else-if="can('cash_registers.restore')" icon size="sm" variant="ghost" title="Restaurer" @click="restore(register)"><RotateCcw class="h-4 w-4" /></Button>
                        </div>
                    </footer>
                </article>
            </div>

            <div v-else class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><WalletCards class="h-5 w-5" /></span>
                <h2 class="mt-3 text-sm font-bold text-foreground">Aucune caisse trouvée</h2>
                <p class="mt-1 text-xs text-muted-foreground">Modifiez les filtres ou créez le premier poste de ce site.</p>
            </div>
        </Card>

        <Dialog :open="showCreate" title="Créer une caisse" :description="`Le poste sera créé sur ${selectedSite?.site.name ?? 'le site choisi'} et protégé par les règles du site.`" size="lg" :dismissible="!createForm.processing" @update:open="setCreateOpen">
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Plus class="h-5 w-5" /></span></template>
            <form id="cash-register-create" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                <FormField label="Site" required :error="createForm.errors.site_code"><Select v-model="createForm.site_code" :options="siteOptions" class="w-full" /></FormField>
                <FormField label="Nom de la caisse" required :error="createForm.errors.name"><Input v-model="createForm.name" placeholder="Ex. Caisse principale" autofocus /></FormField>
                <FormField label="Titulaire Réception / Caisse" hint="(facultatif)" :error="createForm.errors.assigned_user_uuid" class="sm:col-span-2"><Select v-model="createForm.assigned_user_uuid" :options="userOptions" class="w-full" /><p class="mt-1.5 text-xs leading-5 text-muted-foreground">Une caisse attribuée ne peut être ouverte par aucun autre compte Réception.</p></FormField>
                <FormField label="Fond de caisse fixé" required :error="createForm.errors.opening_fund_amount"><Input v-model="createForm.opening_fund_amount" type="number" min="0" step="0.01" /><p class="mt-1.5 text-xs text-muted-foreground">Ce montant sera repris automatiquement à chaque ouverture.</p></FormField>
                <FormField label="Couleur" required :error="createForm.errors.color">
                    <div class="flex h-[var(--control-h)] items-center gap-2 rounded-lg border border-input bg-card px-2 shadow-sm">
                        <button v-for="color in COLORS" :key="color" type="button" :class="['h-6 w-6 rounded-full ring-offset-2 ring-offset-card transition', createForm.color === color ? 'ring-2 ring-primary' : 'hover:scale-110']" :style="{ backgroundColor: color }" :aria-label="`Choisir ${color}`" @click="createForm.color = color" />
                        <input v-model="createForm.color" type="color" class="ms-auto h-7 w-9 cursor-pointer rounded border-0 bg-transparent p-0" aria-label="Couleur personnalisée">
                    </div>
                </FormField>
            </form>
            <template #footer><Button variant="outline" type="button" :disabled="createForm.processing" @click="showCreate = false">Annuler</Button><Button form="cash-register-create" type="submit" :disabled="createForm.processing"><Check class="h-4 w-4" />{{ createForm.processing ? 'Création…' : 'Créer la caisse' }}</Button></template>
        </Dialog>

        <Dialog :open="Boolean(editing)" title="Modifier la caisse" description="Le changement de titulaire est refusé tant qu’une session reste ouverte." size="lg" :dismissible="!editForm.processing" @update:open="(open) => { if (!open) editing = null; }">
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Pencil class="h-5 w-5" /></span></template>
            <form v-if="editing" id="cash-register-edit" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEdit">
                <FormField label="Nom de la caisse" required :error="editForm.errors.name" class="sm:col-span-2"><Input v-model="editForm.name" /></FormField>
                <FormField label="Titulaire Réception / Caisse" hint="(facultatif)" :error="editForm.errors.assigned_user_uuid" class="sm:col-span-2"><Select v-model="editForm.assigned_user_uuid" :options="editUserOptions" class="w-full" /></FormField>
                <FormField label="Fond de caisse fixé" required :error="editForm.errors.opening_fund_amount"><Input v-model="editForm.opening_fund_amount" type="number" min="0" step="0.01" /></FormField>
                <FormField label="Couleur" required :error="editForm.errors.color">
                    <div class="flex h-[var(--control-h)] items-center gap-2 rounded-lg border border-input bg-card px-2 shadow-sm"><button v-for="color in COLORS" :key="color" type="button" :class="['h-6 w-6 rounded-full ring-offset-2 ring-offset-card', editForm.color === color ? 'ring-2 ring-primary' : '']" :style="{ backgroundColor: color }" @click="editForm.color = color" /><input v-model="editForm.color" type="color" class="ms-auto h-7 w-9 cursor-pointer border-0 bg-transparent p-0"></div>
                </FormField>
            </form>
            <template #footer><Button variant="outline" type="button" :disabled="editForm.processing" @click="editing = null">Annuler</Button><Button form="cash-register-edit" type="submit" :disabled="editForm.processing"><Check class="h-4 w-4" />Enregistrer</Button></template>
        </Dialog>

        <Dialog :open="Boolean(configuringMethods)" :title="`Modes acceptés par ${configuringMethods?.name ?? ''}`" description="Sans sélection, la caisse accepte tous les modes actifs du site." :dismissible="!methodsForm.processing" @update:open="(open) => { if (!open) configuringMethods = null; }">
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><LayoutGrid class="h-5 w-5" /></span></template>
            <form v-if="configuringMethods" id="cash-register-methods" class="space-y-2" @submit.prevent="submitMethods">
                <label v-for="method in siteMethods" :key="method.uuid" class="flex cursor-pointer items-center gap-3 rounded-lg border border-border px-3 py-3 hover:bg-muted/50">
                    <Checkbox :model-value="methodsForm.payment_method_uuids.includes(method.uuid)" @update:model-value="toggleMethod(method.uuid, $event)" />
                    <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-foreground">{{ method.name }}</span><span class="text-xs text-muted-foreground">{{ method.category_label }} · {{ method.code }}</span></span>
                </label>
                <p v-if="!siteMethods.length" class="py-8 text-center text-sm text-muted-foreground">Aucun mode de paiement actif sur ce site.</p>
                <p v-if="methodsForm.errors.payment_method_uuids" class="text-xs text-destructive">{{ methodsForm.errors.payment_method_uuids }}</p>
            </form>
            <template #footer><Button variant="outline" type="button" @click="configuringMethods = null">Annuler</Button><Button form="cash-register-methods" type="submit" :disabled="methodsForm.processing"><Check class="h-4 w-4" />Enregistrer</Button></template>
        </Dialog>

        <Dialog :open="Boolean(archiving)" :title="`Archiver ${archiving?.name ?? ''} ?`" description="La caisse disparaîtra des postes disponibles, mais ses sessions et mouvements resteront consultables." :dismissible="!archiveForm.processing" @update:open="(open) => { if (!open) archiving = null; }">
            <template #icon><span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><Archive class="h-5 w-5" /></span></template>
            <form v-if="archiving" id="cash-register-archive" @submit.prevent="submitArchive"><FormField label="Motif d’archivage" required :error="archiveForm.errors.reason"><Textarea v-model="archiveForm.reason" rows="4" placeholder="Précisez pourquoi cette caisse n’est plus utilisée." /></FormField></form>
            <template #footer><Button variant="outline" type="button" @click="archiving = null">Annuler</Button><Button form="cash-register-archive" variant="danger" type="submit" :disabled="archiveForm.processing"><Archive class="h-4 w-4" />Archiver</Button></template>
        </Dialog>
    </div>
</template>
