<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeftRight, AtSign, PlugZap, Ban, CircleAlert, Clock, Copy, KeyRound, MailCheck, MailPlus, MailX, PauseCircle, PlayCircle, Plus, RotateCw, Search, Server, ShieldAlert, UserX,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { highlight, normalizeText, searchStaff } from '@/utilities/staffSearch';

/**
 * ADR-190 — les adresses email professionnelles : demandes, à suspendre,
 * actives, suspendues, historique ; créer, refuser, suspendre, réactiver,
 * nouveau mot de passe.
 *
 * Le même écran sert le portail (tous les sites, par leur API) et la page RH
 * d'un site (ses propres adresses). Seules changent les adresses des gestes
 * (`urls`) et quelques mots (`scope`). Un mot de passe n'est montré qu'une
 * fois, dans la réponse, et n'est enregistré nulle part.
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
    hosting: { type: Object, required: true },
    /** `mailbox(row)` : l'adresse d'une adresse ; `direct(siteCode)` : « Nouvelle adresse » ; `check` : tester la connexion. */
    urls: { type: Object, required: true },
    /** `portal` ou `site` : où l'écran est ouvert. */
    scope: { type: String, default: 'portal' },
});

const { can } = usePermissions();

const VIEWS = [
    { key: 'requested', label: 'Demandes', hint: 'à créer ou refuser', icon: Clock, match: (row) => row.status === 'REQUESTED' },
    { key: 'to_suspend', label: 'À suspendre', hint: 'employé parti', icon: UserX, match: (row) => row.to_suspend },
    { key: 'active', label: 'Actives', hint: 'boîtes en service', icon: MailCheck, match: (row) => row.status === 'ACTIVE' },
    { key: 'suspended', label: 'Suspendues', hint: 'connexion bloquée', icon: PauseCircle, match: (row) => row.status === 'SUSPENDED' },
    { key: 'closed', label: 'Historique', hint: 'refusées, annulées', icon: Ban, match: (row) => ['REJECTED', 'CANCELLED'].includes(row.status) },
];

const STATUS_TONE = { REQUESTED: 'warning', ACTIVE: 'success', SUSPENDED: 'secondary', REJECTED: 'destructive', CANCELLED: 'outline' };

const onlineSites = computed(() => props.sites.filter((site) => site.ok));
const offlineSites = computed(() => props.sites.filter((site) => ! site.ok));

// Ce qui est bon à savoir sans bloquer le travail — accès par mot de passe cPanel,
// site injoignable — tient dans le bouton « ! » de l'en-tête, pas en bandeaux.
const notices = computed(() => [
    ...(props.hosting.configured && props.hosting.auth_mode === 'password'
        ? [{
            key: 'cpanel-password',
            icon: ShieldAlert,
            tone: 'info',
            title: 'Mot de passe cPanel',
            text: 'La connexion à l’hébergeur passe par le mot de passe du compte cPanel : il ouvre aussi l’interface cPanel et ne se révoque pas séparément. Passez à un jeton API dès que votre offre le permet.',
        }]
        : []),
    ...offlineSites.value.map((site) => ({
        key: `site-${site.site.code}`,
        icon: CircleAlert,
        tone: 'warning',
        title: site.site.name,
        text: site.message,
    })),
]);

const rows = computed(() => onlineSites.value.flatMap((site) => (site.data ?? []).map((mailbox) => ({
    ...mailbox,
    site_code: site.site.code,
    site_name: site.site.name,
}))));

const view = ref('requested');
const siteFilter = ref('ALL');
const query = ref('');

const inSite = (row) => siteFilter.value === 'ALL' || row.site_code === siteFilter.value;
const matchesQuery = (row) => {
    const terms = normalizeText(query.value).split(/\s+/).filter(Boolean);
    const haystack = normalizeText([row.address, row.employee?.name, row.employee?.employee_number, row.employee?.job_title, row.site_name].join(' '));

    return terms.every((term) => haystack.includes(term));
};

const counts = computed(() => Object.fromEntries(VIEWS.map((entry) => [entry.key, rows.value.filter((row) => inSite(row) && entry.match(row)).length])));
const shown = computed(() => {
    const entry = VIEWS.find((candidate) => candidate.key === view.value) ?? VIEWS[0];

    return rows.value.filter((row) => inSite(row) && entry.match(row) && matchesQuery(row));
});

const localPartOf = (address) => String(address ?? '').split('@')[0];
const base = (row) => props.urls.mailbox(row);
const reload = () => router.reload({ only: ['sites'], preserveScroll: true });

// — JSON : créer, nouveau mot de passe. Le mot de passe ne passe jamais par la session.
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const postJson = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));

    return { ok: response.ok, payload };
};

// — La connexion à l'hébergeur est l'étape lente : elle se fait pendant que la fenêtre
// se remplit, pour que « Créer » (ou suspendre, réactiver…) n'ait plus à l'attendre.
// Rien n'est créé ; le serveur garde la session quelques minutes.
let preparedAt = 0;
const prepareHost = async () => {
    if (! props.urls.prepare || ! props.hosting.configured || props.hosting.auth_mode !== 'password') return;
    if (Date.now() - preparedAt < 4 * 60 * 1000) return;
    preparedAt = Date.now();
    const { ok } = await postJson(props.urls.prepare).catch(() => ({ ok: false }));
    if (! ok) preparedAt = 0;
};

const createTarget = ref(null);
const createLocalPart = ref('');
const createError = ref('');
const creating = ref(false);
const openCreate = (row) => {
    prepareHost();
    createTarget.value = row;
    createLocalPart.value = localPartOf(row.address);
    createError.value = '';
};

const result = ref(null);
const copied = ref(false);

const submitCreate = async () => {
    const row = createTarget.value;
    if (! row || creating.value) return;
    creating.value = true;
    createError.value = '';
    const { ok, payload } = await postJson(`${base(row)}/create`, { local_part: createLocalPart.value.trim().toLowerCase() });
    creating.value = false;

    if (! ok) {
        createError.value = payload?.errors?.local_part?.[0] ?? payload?.message ?? 'La création a échoué.';

        return;
    }

    createTarget.value = null;
    result.value = { ...payload, row, kind: 'create' };
    copied.value = false;
    reload();
};

const retryConfirmation = async () => {
    const row = result.value?.row;
    if (! row || creating.value) return;
    creating.value = true;
    const { ok, payload } = await postJson(`${base(row)}/create`, { local_part: localPartOf(result.value.address) });
    creating.value = false;
    // Le mot de passe du premier essai reste affiché : un nouvel essai ne recrée rien.
    result.value = { ...result.value, confirmed: ok && payload.confirmed, message: payload?.message ?? result.value.message };
    reload();
};

const passwordTarget = ref(null);
const resetting = ref(false);
const resetError = ref('');
const submitReset = async () => {
    const row = passwordTarget.value;
    if (! row || resetting.value) return;
    resetting.value = true;
    resetError.value = '';
    const { ok, payload } = await postJson(`${base(row)}/password`);
    resetting.value = false;

    if (! ok) {
        resetError.value = payload?.message ?? 'Le mot de passe n’a pas pu être changé.';

        return;
    }

    passwordTarget.value = null;
    result.value = { ...payload, row, kind: 'password' };
    copied.value = false;
};

// — « Nouvelle adresse » : le Super Admin choisit lui-même l'employé.
const canCreateDirect = computed(() => can('professional_emails.create') && can('professional_emails.request'));
const directSites = computed(() => onlineSites.value.filter((site) => Array.isArray(site.meta?.candidates)));
const newOpen = ref(false);
const newSite = ref('');
const newQuery = ref('');
const newEmployee = ref(null);
const newLocalPart = ref('');
const newError = ref('');
const newCreating = ref(false);

const newCandidates = computed(() => directSites.value.find((site) => site.site.code === newSite.value)?.meta?.candidates ?? []);
const newResults = computed(() => searchStaff(newCandidates.value, newQuery.value, { limit: newQuery.value.trim() ? 8 : 6 }));

const openNew = () => {
    prepareHost();
    newSite.value = siteFilter.value !== 'ALL' ? siteFilter.value : (directSites.value[0]?.site.code ?? '');
    newQuery.value = '';
    newEmployee.value = null;
    newLocalPart.value = '';
    newError.value = '';
    newOpen.value = true;
};
const chooseSite = (code) => {
    newSite.value = code;
    newEmployee.value = null;
    newLocalPart.value = '';
    newError.value = '';
};
const chooseEmployee = (employee) => {
    newEmployee.value = employee;
    newLocalPart.value = employee.suggestion ?? '';
    newError.value = '';
};

const submitNew = async () => {
    if (! newEmployee.value || newCreating.value) return;
    newCreating.value = true;
    newError.value = '';
    const { ok, payload } = await postJson(props.urls.direct(newSite.value), {
        employee_uuid: newEmployee.value.uuid,
        local_part: newLocalPart.value.trim().toLowerCase(),
    });
    newCreating.value = false;

    if (! ok) {
        newError.value = payload?.errors?.local_part?.[0] ?? payload?.errors?.employee_uuid?.[0] ?? payload?.message ?? 'La création a échoué.';
        reload();

        return;
    }

    const site = directSites.value.find((entry) => entry.site.code === newSite.value);
    newOpen.value = false;
    result.value = { ...payload, row: { uuid: payload.mailbox_uuid, site_code: newSite.value, site_name: site?.site.name }, kind: 'create' };
    copied.value = false;
    reload();
};

// — « Tester la connexion » : une lecture seule chez l'hébergeur, rien n'est créé.
const checking = ref(false);
const checkResult = ref(null);
const checkConnection = async () => {
    if (checking.value) return;
    checking.value = true;
    checkResult.value = null;
    const { ok, payload } = await postJson(props.urls.check);
    checking.value = false;
    checkResult.value = { ok, message: payload?.message ?? (ok ? 'Connexion réussie.' : 'Le test a échoué.') };
};

const copyPassword = async () => {
    try {
        await navigator.clipboard.writeText(result.value.password);
        copied.value = true;
    } catch {
        copied.value = false;
    }
};

// — Refuser, suspendre (motif) ; réactiver (confirmation).
const reasonTarget = ref(null);
const reasonAction = ref('');
const reasonForm = useForm({ reason: '' });
const openReason = (row, action) => {
    if (action === 'suspend') prepareHost();
    reasonTarget.value = row;
    reasonAction.value = action;
    reasonForm.reset();
    reasonForm.clearErrors();
};
const submitReason = () => reasonForm.post(`${base(reasonTarget.value)}/${reasonAction.value}`, {
    preserveScroll: true,
    onSuccess: () => { reasonTarget.value = null; },
});
const reasonError = computed(() => reasonForm.errors.reason || reasonForm.errors.mailbox || reasonForm.errors.site || '');

const reactivateTarget = ref(null);
watch([passwordTarget, reactivateTarget], ([password, reactivate]) => {
    if (password || reactivate) prepareHost();
});
const reactivating = ref(false);
const reactivateError = ref('');
const submitReactivate = () => {
    reactivating.value = true;
    reactivateError.value = '';
    router.post(`${base(reactivateTarget.value)}/reactivate`, {}, {
        preserveScroll: true,
        onSuccess: () => { reactivateTarget.value = null; },
        onError: (errors) => { reactivateError.value = Object.values(errors)[0] ?? 'La réactivation a échoué.'; },
        onFinish: () => { reactivating.value = false; },
    });
};
</script>

<template>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true"><AtSign class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{{ scope === 'portal' ? 'Organisation' : 'Ressources humaines' }}</p>
                    <h1 class="font-heading text-2xl font-bold text-foreground">Emails professionnels</h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">{{ scope === 'portal' ? 'Le RH de chaque site demande l’adresse depuis la fiche employé ; vous la créez ici, chez l’hébergeur.' : 'Les adresses @' + hosting.domain + ' du personnel de ce site : demandées depuis la fiche employé, créées chez l’hébergeur par qui en a reçu le droit.' }} Le mot de passe n’est montré qu’une fois.</p>
                </div>
            </div>
            <div class="flex flex-col items-stretch gap-2 sm:items-end">
            <Button v-if="canCreateDirect" type="button" :disabled="! hosting.configured || ! directSites.length" :title="! hosting.configured ? 'Hébergeur non configuré' : ''" @click="openNew"><Plus class="h-4 w-4" />Nouvelle adresse</Button>
            <div class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-xs text-muted-foreground shadow-sm">
                <Server class="h-3.5 w-3.5" />
                <span v-if="hosting.configured">{{ hosting.server }} · <span class="font-mono text-foreground">@{{ hosting.domain }}</span> · {{ hosting.quota_mb }} Mo par boîte · {{ hosting.auth_mode === 'token' ? 'jeton API' : 'mot de passe cPanel' }}</span>
                <span v-else class="font-semibold text-amber-700 dark:text-amber-300">Hébergeur non configuré</span>
            </div>
            <div v-if="(hosting.configured && can('professional_emails.create')) || notices.length" class="flex items-center gap-2 sm:justify-end">
                <Button v-if="hosting.configured && can('professional_emails.create')" type="button" size="sm" variant="white-outline" :disabled="checking" @click="checkConnection">
                    <PlugZap class="h-3.5 w-3.5" />{{ checking ? 'Test en cours…' : 'Tester la connexion' }}
                </Button>
                <NoticesButton :notices="notices" subtitle="Rien ici n’empêche de travailler." />
            </div>
            <p v-if="checkResult" role="status" :class="cn('max-w-md text-xs leading-5 sm:text-end', checkResult.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive')">{{ checkResult.message }}</p>
            </div>
        </header>

        <div v-if="! hosting.configured || ! hosting.domain" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100" role="status">
            <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-semibold">{{ scope === 'portal' ? 'La création est impossible tant que l’hébergeur n’est pas configuré sur le portail.' : 'La création se fait depuis le portail : l’accès à l’hébergeur n’est pas posé sur ce site.' }}</p>
                <p class="mt-1 text-xs leading-5">Dans le fichier <code>.env</code> {{ scope === 'portal' ? 'du portail' : 'de ce site' }} : <code>RIVO_PROFESSIONAL_EMAIL_DOMAIN</code>, <code>RIVO_MAIL_HOSTING_URL</code>, <code>RIVO_MAIL_HOSTING_USER</code>, puis <code>RIVO_MAIL_HOSTING_TOKEN</code> (cPanel › Sécurité › Gérer les jetons API) ou, si votre offre n’ouvre pas les jetons, <code>RIVO_MAIL_HOSTING_PASSWORD</code>. {{ scope === 'portal' ? 'Les demandes des sites restent visibles.' : 'Les demandes restent possibles : le Super Admin les traite.' }}</p>
            </div>
        </div>

        <!-- Les vues : chaque carte est un filtre, son compte vient des sites. -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" role="tablist" aria-label="Vues">
            <button
                v-for="entry in VIEWS"
                :key="entry.key"
                type="button"
                role="tab"
                :aria-selected="view === entry.key"
                :class="cn(
                    'flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    view === entry.key ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40',
                )"
                @click="view = entry.key"
            >
                <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', entry.key === 'to_suspend' && counts[entry.key] ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-muted text-muted-foreground')"><component :is="entry.icon" class="h-4 w-4" /></span>
                <span class="min-w-0">
                    <span class="block text-xl font-bold tabular-nums text-foreground">{{ counts[entry.key] }}</span>
                    <span class="block truncate text-xs font-semibold text-foreground">{{ entry.label }}</span>
                    <span class="block truncate text-[11px] text-muted-foreground">{{ entry.hint }}</span>
                </span>
            </button>
        </div>

        <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                <div v-if="onlineSites.length > 1" class="flex flex-wrap gap-1.5" role="group" aria-label="Site">
                    <Button type="button" size="sm" :variant="siteFilter === 'ALL' ? 'default' : 'white-outline'" @click="siteFilter = 'ALL'">Tous les sites</Button>
                    <Button v-for="site in onlineSites" :key="site.site.code" type="button" size="sm" :variant="siteFilter === site.site.code ? 'default' : 'white-outline'" @click="siteFilter = site.site.code">{{ site.site.name }}</Button>
                </div>
                <div :class="cn('w-full lg:w-72', onlineSites.length <= 1 && 'lg:ms-auto')">
                    <IconInput v-model="query" :icon="Search" type="text" placeholder="Nom, matricule, adresse…" aria-label="Rechercher" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 text-start font-semibold">Employé</th>
                            <th class="px-4 py-2.5 text-start font-semibold">Adresse</th>
                            <th class="px-4 py-2.5 text-start font-semibold">État</th>
                            <th class="px-4 py-2.5 text-start font-semibold">Demande</th>
                            <th class="px-4 py-2.5 text-end font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in shown" :key="`${row.site_code}-${row.uuid}`" class="align-top">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-foreground">{{ row.employee?.name ?? '—' }}</p>
                                <p class="text-xs text-muted-foreground"><span class="font-mono">{{ row.employee?.employee_number }}</span><template v-if="row.employee?.job_title"> · {{ row.employee.job_title }}</template> · {{ row.site_name }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-sm text-foreground">{{ row.address }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge :variant="STATUS_TONE[row.status] ?? 'outline'">{{ row.status_label }}</Badge>
                                    <Badge v-if="row.to_suspend" variant="warning" class="gap-1"><UserX class="h-3 w-3" />Employé parti</Badge>
                                </div>
                                <p v-if="row.rejection_reason" class="mt-1 text-xs text-muted-foreground">{{ row.rejection_reason }}</p>
                                <p v-if="row.suspension_reason && row.status === 'SUSPENDED'" class="mt-1 text-xs text-muted-foreground">{{ row.suspension_reason }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ formatDateTime(row.requested_at) }}<template v-if="row.requested_by"><br>par {{ row.requested_by }}</template>
                                <p v-if="row.request_note" class="mt-1 italic">« {{ row.request_note }} »</p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <template v-if="row.status === 'REQUESTED'">
                                        <Button v-if="can('professional_emails.create')" type="button" size="sm" :disabled="! hosting.configured" @click="openCreate(row)"><MailPlus class="h-3.5 w-3.5" />Créer</Button>
                                        <Button v-if="can('professional_emails.reject')" type="button" size="sm" variant="white-outline" @click="openReason(row, 'reject')"><Ban class="h-3.5 w-3.5" />Refuser</Button>
                                    </template>
                                    <template v-else-if="row.status === 'ACTIVE'">
                                        <Button v-if="can('professional_emails.update')" type="button" size="sm" variant="white-outline" :disabled="! hosting.configured" @click="passwordTarget = row; resetError = ''"><KeyRound class="h-3.5 w-3.5" />Nouveau mot de passe</Button>
                                        <Button v-if="can('professional_emails.deactivate')" type="button" size="sm" :variant="row.to_suspend ? 'warning' : 'white-outline'" :disabled="! hosting.configured" @click="openReason(row, 'suspend')"><PauseCircle class="h-3.5 w-3.5" />Suspendre</Button>
                                    </template>
                                    <Button v-else-if="row.status === 'SUSPENDED' && can('professional_emails.activate')" type="button" size="sm" variant="white-outline" :disabled="! hosting.configured" @click="reactivateTarget = row; reactivateError = ''"><PlayCircle class="h-3.5 w-3.5" />Réactiver</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="! shown.length" class="px-4 py-12 text-center">
                    <MailX class="mx-auto h-8 w-8 text-muted-foreground/60" />
                    <p class="mt-2 text-sm font-semibold text-foreground">Rien dans cette vue</p>
                    <p class="mt-1 text-xs text-muted-foreground">Les demandes arrivent quand le RH d’un site demande une adresse depuis une fiche employé<template v-if="canCreateDirect"> — ou créez-en une vous-même avec « Nouvelle adresse »</template>.</p>
                </div>
            </div>
        </section>
    </div>

    <!-- Créer la boîte. -->
    <Dialog :open="createTarget !== null" title="Créer l’adresse chez l’hébergeur" :description="createTarget ? `${createTarget.employee?.name} · ${createTarget.site_name}` : ''" :dismissible="! creating" @update:open="(open) => { if (! open && ! creating) createTarget = null; }">
        <form v-if="createTarget" id="create-mailbox" class="space-y-3" novalidate @submit.prevent="submitCreate">
            <FormField label="Adresse" required :error="createError">
                <div class="flex items-stretch">
                    <Input v-model="createLocalPart" class="rounded-e-none font-mono" autocomplete="off" spellcheck="false" :aria-invalid="Boolean(createError)" />
                    <span class="inline-flex items-center rounded-e-md border border-s-0 border-input bg-muted px-3 font-mono text-sm text-muted-foreground">@{{ hosting.domain }}</span>
                </div>
            </FormField>
            <p class="text-xs leading-5 text-muted-foreground">Un mot de passe fort est généré et <strong class="text-foreground">affiché une seule fois</strong>. Une fois créée, l’adresse devient l’email de la fiche employé sur {{ createTarget.site_name }}. Boîte de {{ hosting.quota_mb }} Mo.</p>
        </form>
        <template #footer>
            <Button type="button" variant="white-outline" :disabled="creating" @click="createTarget = null">Annuler</Button>
            <Button type="submit" form="create-mailbox" :disabled="creating || ! createLocalPart.trim()"><MailPlus class="h-4 w-4" />{{ creating ? 'Création…' : 'Créer la boîte' }}</Button>
        </template>
    </Dialog>

    <!-- Nouvelle adresse : site, employé, adresse. -->
    <Dialog :open="newOpen" title="Nouvelle adresse professionnelle" description="Choisissez l’employé : l’adresse est proposée depuis sa fiche." size="lg" :dismissible="! newCreating" @update:open="(open) => { if (! open && ! newCreating) newOpen = false; }">
        <form id="new-mailbox" class="space-y-4" novalidate @submit.prevent="submitNew">
            <div v-if="directSites.length > 1" class="flex flex-wrap gap-1.5" role="group" aria-label="Site">
                <Button v-for="site in directSites" :key="site.site.code" type="button" size="sm" :variant="newSite === site.site.code ? 'default' : 'white-outline'" @click="chooseSite(site.site.code)">{{ site.site.name }}</Button>
            </div>

            <div v-if="newEmployee" class="flex items-center gap-3 rounded-lg border border-border p-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-primary-foreground" aria-hidden="true">{{ (newEmployee.first_name?.[0] ?? '') + (newEmployee.last_name?.[0] ?? '') }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-foreground">{{ newEmployee.name }}</p>
                    <p class="truncate text-xs text-muted-foreground"><span class="font-mono">{{ newEmployee.employee_number }}</span><template v-if="newEmployee.job_title"> · {{ newEmployee.job_title }}</template><template v-if="newEmployee.department"> · {{ newEmployee.department }}</template></p>
                </div>
                <Button type="button" size="sm" variant="white-outline" @click="newEmployee = null; newLocalPart = ''"><ArrowLeftRight class="h-3.5 w-3.5" />Changer</Button>
            </div>
            <div v-else class="space-y-2">
                <IconInput v-model="newQuery" :icon="Search" type="text" placeholder="Nom, matricule, fonction ou service…" aria-label="Rechercher l’employé" autofocus />
                <ul v-if="newResults.results.length" class="max-h-64 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="employee in newResults.results" :key="employee.uuid">
                        <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-start transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none" @click="chooseEmployee(employee)">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-foreground"><template v-for="(part, index) in highlight(employee.name, newQuery)" :key="index"><mark v-if="part.match" class="rounded-[2px] bg-primary/15 text-foreground">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                                <span class="block truncate text-xs text-muted-foreground"><span class="font-mono">{{ employee.employee_number }}</span><template v-if="employee.job_title"> · {{ employee.job_title }}</template></span>
                            </span>
                            <span class="hidden shrink-0 font-mono text-xs text-muted-foreground sm:block">{{ employee.suggestion }}@{{ hosting.domain }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="rounded-lg border border-dashed border-border px-3 py-5 text-center text-sm text-muted-foreground">
                    {{ newCandidates.length ? `Aucun employé ne correspond à « ${newQuery} ».` : 'Tous les employés en poste de ce site ont déjà une adresse, ou une demande en attente.' }}
                </p>
                <p v-if="newQuery.trim() && newResults.total > newResults.results.length" class="text-xs text-muted-foreground">{{ newResults.total - newResults.results.length }} autre(s) — précisez la recherche.</p>
            </div>

            <FormField v-if="newEmployee" label="Adresse" required :error="newError">
                <div class="flex items-stretch">
                    <Input v-model="newLocalPart" class="rounded-e-none font-mono" autocomplete="off" spellcheck="false" :aria-invalid="Boolean(newError)" />
                    <span class="inline-flex items-center rounded-e-md border border-s-0 border-input bg-muted px-3 font-mono text-sm text-muted-foreground">@{{ hosting.domain }}</span>
                </div>
            </FormField>
            <p v-else-if="newError" class="text-sm text-destructive">{{ newError }}</p>
            <p v-if="newEmployee" class="text-xs leading-5 text-muted-foreground">Un mot de passe fort est généré et <strong class="text-foreground">affiché une seule fois</strong>. L’adresse devient l’email de la fiche employé. La demande est aussi enregistrée sur le site, pour la trace.</p>
        </form>
        <template #footer>
            <Button type="button" variant="white-outline" :disabled="newCreating" @click="newOpen = false">Annuler</Button>
            <Button type="submit" form="new-mailbox" :disabled="newCreating || ! newEmployee || ! newLocalPart.trim()"><MailPlus class="h-4 w-4" />{{ newCreating ? 'Création…' : 'Créer la boîte' }}</Button>
        </template>
    </Dialog>

    <!-- Le mot de passe, une seule fois. -->
    <Dialog :open="result !== null" :title="result?.kind === 'password' ? 'Nouveau mot de passe' : 'Boîte créée'" :description="result?.address ?? ''" :dismissible="false" @update:open="(open) => { if (! open) result = null; }">
        <div v-if="result" class="space-y-4">
            <div v-if="result.password" class="rounded-lg border border-border bg-muted/40 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Mot de passe</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 select-all break-all rounded-md bg-card px-3 py-2 font-mono text-lg font-bold tracking-wider text-foreground">{{ result.password }}</code>
                    <Button type="button" size="sm" variant="white-outline" @click="copyPassword"><Copy class="h-3.5 w-3.5" />{{ copied ? 'Copié' : 'Copier' }}</Button>
                </div>
                <p class="mt-3 flex items-start gap-2 text-xs leading-5 text-amber-800 dark:text-amber-200"><ShieldAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />Il ne sera plus jamais affiché. Remettez-le à la personne en main propre ; elle pourra le changer dans son webmail.</p>
            </div>
            <p v-else class="text-sm text-muted-foreground">La boîte avait déjà été créée lors d’un essai précédent : son mot de passe a été montré à ce moment-là. S’il est perdu, utilisez « Nouveau mot de passe ».</p>
            <p :class="cn('flex items-start gap-2 rounded-md px-3 py-2 text-sm', result.confirmed ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100')">
                <component :is="result.confirmed ? MailCheck : CircleAlert" class="mt-0.5 h-4 w-4 shrink-0" />{{ result.message }}
            </p>
        </div>
        <template #footer>
            <Button v-if="result && ! result.confirmed" type="button" variant="white-outline" :aria-busy="creating" :disabled="creating" @click="retryConfirmation"><RefreshIcon :spinning="creating" :icon="RotateCw" class="h-4 w-4" />{{ creating ? 'Nouvel essai…' : 'Réessayer l’enregistrement' }}</Button>
            <Button type="button" @click="result = null">{{ result?.password ? 'J’ai noté le mot de passe' : 'Fermer' }}</Button>
        </template>
    </Dialog>

    <!-- Refuser ou suspendre : un motif. -->
    <Dialog :open="reasonTarget !== null" :title="reasonAction === 'reject' ? 'Refuser la demande' : 'Suspendre la boîte'" :description="reasonTarget?.address ?? ''" @update:open="(open) => { if (! open) reasonTarget = null; }">
        <form id="reason-form" class="space-y-3" novalidate @submit.prevent="submitReason">
            <p v-if="reasonAction === 'suspend'" class="text-sm text-muted-foreground">La connexion, l’envoi et la lecture sont bloqués chez l’hébergeur. La boîte et ses messages restent ; elle peut être réactivée.</p>
            <FormField label="Motif" required :error="reasonError">
                <Textarea v-model="reasonForm.reason" rows="3" :placeholder="reasonAction === 'reject' ? 'Pourquoi cette adresse n’est pas créée…' : 'Départ de la clinique, fin de contrat…'" />
            </FormField>
        </form>
        <template #footer>
            <Button type="button" variant="white-outline" @click="reasonTarget = null">Annuler</Button>
            <Button type="submit" form="reason-form" :variant="reasonAction === 'reject' ? 'destructive' : 'warning'" :disabled="reasonForm.processing || reasonForm.reason.trim().length < 5">
                {{ reasonAction === 'reject' ? 'Refuser' : 'Suspendre' }}
            </Button>
        </template>
    </Dialog>

    <ConfirmModal
        :open="passwordTarget !== null"
        title="Définir un nouveau mot de passe ?"
        :description="passwordTarget ? `L’ancien mot de passe de ${passwordTarget.address} cesse de fonctionner. Le nouveau sera affiché une seule fois.${resetError ? ' — ' + resetError : ''}` : ''"
        confirm-label="Générer un nouveau mot de passe"
        :icon="KeyRound"
        tone="warning"
        :processing="resetting"
        @update:open="(open) => { if (! open) passwordTarget = null; }"
        @confirm="submitReset"
    />

    <ConfirmModal
        :open="reactivateTarget !== null"
        title="Réactiver la boîte ?"
        :description="reactivateTarget ? `${reactivateTarget.address} pourra de nouveau se connecter, avec son ancien mot de passe.${reactivateError ? ' — ' + reactivateError : ''}` : ''"
        confirm-label="Réactiver"
        :icon="PlayCircle"
        tone="success"
        :processing="reactivating"
        @update:open="(open) => { if (! open) reactivateTarget = null; }"
        @confirm="submitReactivate"
    />
</template>
