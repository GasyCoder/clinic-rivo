<script setup>
import { computed, ref, watch } from 'vue';
import {
    AtSign,
    Building2,
    Check,
    CircleAlert,
    CircleCheck,
    Copy,
    KeyRound,
    Loader2,
    RotateCcw,
    Send,
    ShieldCheck,
    Sparkles,
    UserPlus,
    UserRound,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Select from '@/Components/Shadcn/Select.vue';
import StaffAccessReceiverPicker from '@/Components/StaffAccess/StaffAccessReceiverPicker.vue';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';
import { hasActiveMailbox, initialLocalPart, proposedSelection, rowProblem } from '@/utilities/staffAccess';
import { avatarTone, initialsOf } from '@/utilities/webmail';

/**
 * ADR-197 / ADR-202 — créer l'accès d'un ou plusieurs employés : pour chacun, son
 * adresse et son rôle. Chaque accès part à son tour (la boîte chez l'hébergeur,
 * puis le compte sur le site) et son état s'affiche : un refus n'empêche pas les
 * autres et se reprend d'un clic. Aucun mot de passe : l'employé choisit le sien à
 * sa première connexion, et sa boîte reçoit le même.
 *
 * ADR-199 — le rôle arrive prérempli par la fonction de l'employé (réglée dans le
 * module Fonctions) : on ne le choisit que si la fonction n'en propose aucun, ou
 * pour le changer. Rien n'est signalé comme manquant avant qu'on ait agi.
 *
 * Une fois créés, les accès d'un site s'envoient à son RH, qui les remet.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** Les employés choisis, chacun avec `site_code` et `site_name`. */
    employees: { type: Array, default: () => [] },
    /** Les rôles de chaque site, par code de site. */
    rolesBySite: { type: Object, default: () => ({}) },
    /** Le nombre de comptes RH qui recevront les accès, par site. */
    receiversBySite: { type: Object, default: () => ({}) },
    /** ADR-199 — les comptes actifs de chaque site, pour désigner qui remettra les accès. */
    accountsBySite: { type: Object, default: () => ({}) },
    /** ADR-199 — le Super Admin peut confier la remise à un compte du site. */
    canDesignate: { type: Boolean, default: false },
    domain: { type: String, default: null },
    /** ADR-202 — le délai pour la première connexion. */
    activationDays: { type: Number, default: 14 },
});
const emit = defineEmits(['update:open', 'finished']);

const toast = useToastStore();
const rows = ref([]);
const phase = ref('form');
const handovers = ref({});
const sending = ref({});
const sent = ref({});
const bulkRole = ref('');

const rolesOf = (site) => props.rolesBySite[site] ?? [];
/** Qui peut recevoir, par site — mis à jour dès qu'une personne est désignée ici. */
const designated = ref({});
const receiversOf = (site) => designated.value[site]?.receivers ?? props.receiversBySite[site] ?? 0;
const accountsOf = (site) => designated.value[site]?.accounts ?? props.accountsBySite[site] ?? [];
const onDesignated = (site, result) => { designated.value = { ...designated.value, [site]: result }; };

const reset = () => {
    rows.value = props.employees.map((employee) => {
        const proposal = proposedSelection(employee, rolesOf(employee.site_code));

        return {
            key: `${employee.site_code}:${employee.uuid}`,
            employee,
            site: employee.site_code,
            local_part: initialLocalPart(employee),
            role_id: proposal.role_id,
            profile_id: proposal.profile_id,
            proposal,
            touched: false,
            status: 'idle',
            message: '',
            result: null,
            handover: null,
        };
    });
    phase.value = 'form';
    handovers.value = {};
    sending.value = {};
    sent.value = {};
    bulkRole.value = '';
    designated.value = {};
};

watch(() => props.open, (open) => { if (open) reset(); });

const sites = computed(() => [...new Set(rows.value.map((row) => row.site))]);
const singleSite = computed(() => (sites.value.length === 1 ? sites.value[0] : null));
const single = computed(() => (rows.value.length === 1 ? rows.value[0] : null));
const roleOptions = (site) => [{ value: '', label: 'Choisir le rôle' }, ...rolesOf(site).map((role) => ({ value: String(role.id), label: role.name }))];
const profilesOf = (site, roleId) => rolesOf(site).find((role) => String(role.id) === String(roleId))?.profiles ?? [];
const profileOptions = (site, roleId) => [{ value: '', label: 'Choisir le profil' }, ...profilesOf(site, roleId).map((profile) => ({ value: String(profile.id), label: profile.name }))];

const setRole = (row, value) => {
    row.role_id = value;
    row.profile_id = '';
    row.touched = true;
};

/** Un rôle pour toutes les lignes encore à créer — puis chacune reste ajustable. */
const applyBulk = (value) => {
    bulkRole.value = value;
    if (!value) return;
    rows.value.forEach((row) => {
        if (row.status === 'done') return;
        setRole(row, value);
    });
};

const problemOf = (row) => rowProblem(row, rolesOf(row.site));
/** Un manque ne se dit qu'après un geste, ou après un refus : à l'ouverture, rien n'est « en erreur ». */
const visibleProblem = (row) => ((row.touched || row.status === 'failed') ? problemOf(row) : null);
const fromJobTitle = (row) => row.proposal.fromJobTitle && String(row.role_id) === row.proposal.role_id;

const pendingRows = computed(() => rows.value.filter((row) => row.status !== 'done'));
const incomplete = computed(() => pendingRows.value.filter((row) => problemOf(row)));
const ready = computed(() => rows.value.length > 0 && incomplete.value.length === 0 && pendingRows.value.length > 0);
const done = computed(() => rows.value.filter((row) => row.status === 'done'));
const failed = computed(() => rows.value.filter((row) => row.status === 'failed'));
const running = computed(() => rows.value.some((row) => row.status === 'running'));

/** Les accès créés, par remise : ceux d'un même site partent ensemble au RH. */
const doneBySite = computed(() => {
    const groups = new Map();
    for (const row of done.value) {
        if (!groups.has(row.handover)) groups.set(row.handover, { uuid: row.handover, site: row.site, name: row.employee.site_name ?? row.site, rows: [] });
        groups.get(row.handover).rows.push(row);
    }

    return [...groups.values()];
});

const title = computed(() => (single.value ? `Créer l’accès de ${single.value.employee.name}` : `Créer l’accès de ${rows.value.length} employés`));
const description = computed(() => (phase.value === 'form'
    ? 'Une adresse professionnelle et un compte RIVO. L’employé choisira lui-même son mot de passe.'
    : phase.value === 'running' ? 'Chaque accès est créé à son tour : l’adresse chez l’hébergeur, puis le compte sur le site.'
        : 'Envoyez-les au RH du site : il dira à chaque employé que son compte existe et où se connecter.'));

const STEPS = [
    { icon: AtSign, label: 'Adresse pro' },
    { icon: UserRound, label: 'Compte RIVO' },
    { icon: Send, label: 'Au RH' },
    { icon: KeyRound, label: 'Mot de passe choisi par l’employé' },
];

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const post = async (url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    return { ok: response.ok, json };
};

const firstError = (json) => {
    const errors = Object.values(json?.errors ?? {}).flat().filter(Boolean);

    return errors.length ? errors[0] : (json?.message ?? 'Le serveur n’a pas répondu.');
};

/** Une remise par site et par passage dans cette fenêtre : les accès créés ensemble partent ensemble. */
const handoverFor = (site) => {
    handovers.value[site] ??= crypto.randomUUID();

    return handovers.value[site];
};

const grant = async (row) => {
    row.status = 'running';
    row.message = '';
    row.handover = handoverFor(row.site);
    try {
        const { ok, json } = await post(`/super-admin/staff-access/${row.site}/grant`, {
            handover_uuid: row.handover,
            employee_uuid: row.employee.uuid,
            local_part: hasActiveMailbox(row.employee) ? null : String(row.local_part).trim().toLowerCase(),
            role_id: Number(row.role_id),
            professional_profile_id: row.profile_id ? Number(row.profile_id) : null,
        });
        if (ok) {
            row.status = 'done';
            row.result = json;
        } else {
            row.status = 'failed';
            row.message = firstError(json);
        }
    } catch {
        row.status = 'failed';
        row.message = 'Le portail n’a pas répondu. Réessayez.';
    }
};

const run = async () => {
    if (!ready.value) return;
    phase.value = 'running';
    for (const row of rows.value) {
        if (row.status !== 'done') await grant(row);
    }
    phase.value = 'done';
    if (done.value.length) toast.success(`${done.value.length} accès créé${done.value.length > 1 ? 's' : ''}. Envoyez-les au RH pour qu’il prévienne les employés.`);
};

const retry = async (row) => {
    // La remise de ce site est déjà partie : l'accès repris part dans une nouvelle remise.
    if (sent.value[handovers.value[row.site]]) delete handovers.value[row.site];
    await grant(row);
};

const send = async (group) => {
    sending.value[group.uuid] = true;
    try {
        const { ok, json } = await post(`/super-admin/staff-access/${group.site}/handovers/${group.uuid}/send`, {});
        if (ok) {
            sent.value[group.uuid] = json.message ?? 'Envoyé au RH.';
            toast.success(sent.value[group.uuid]);
        } else {
            toast.error(firstError(json));
        }
    } catch {
        toast.error('Le portail n’a pas répondu. Réessayez.');
    } finally {
        sending.value[group.uuid] = false;
    }
};

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copié.');
    } catch {
        toast.error('Copie impossible : sélectionnez le texte.');
    }
};

const close = (value) => {
    if (!value && running.value) return;
    emit('update:open', value);
    if (!value && done.value.length) emit('finished');
};

const who = (employee) => [employee.job_title, employee.department, employee.employee_number].filter(Boolean).join(' · ') || '—';
</script>

<template>
    <Dialog
        :open="open"
        :title="title"
        :description="description"
        size="xl"
        :dismissible="!running"
        body-class="px-0 py-0"
        close-label="Fermer"
        @update:open="close"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><UserPlus class="h-5 w-5" aria-hidden="true" /></span>
        </template>

        <div class="max-h-[calc(100vh-16rem)] overflow-y-auto">
            <!-- Ce que crée un accès : trois étapes, lues d'un coup d'œil -->
            <ol class="flex items-center gap-2 border-b border-border bg-muted/30 px-6 py-3 text-xs font-medium text-muted-foreground" aria-label="Ce que crée un accès">
                <template v-for="(step, index) in STEPS" :key="step.label">
                    <li class="flex items-center gap-1.5">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-card text-primary ring-1 ring-border"><component :is="step.icon" class="h-3.5 w-3.5" aria-hidden="true" /></span>
                        {{ step.label }}
                    </li>
                    <li v-if="index < STEPS.length - 1" class="h-px min-w-3 flex-1 bg-border" aria-hidden="true" />
                </template>
            </ol>

            <!-- Un rôle pour tous (un seul site, plusieurs employés) -->
            <div v-if="phase === 'form' && singleSite && rows.length > 1" class="flex flex-col gap-2 border-b border-border px-6 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted-foreground">Chaque rôle vient de la fonction de l’employé. Pour les remplacer tous :</p>
                <Select :model-value="bulkRole" :options="[{ value: '', label: 'Même rôle pour tous…' }, ...roleOptions(singleSite).slice(1)]" class="sm:w-56" aria-label="Même rôle pour tous" @update:model-value="applyBulk" />
            </div>

            <ul class="space-y-3 p-4 sm:px-6">
                <li
                    v-for="row in rows"
                    :key="row.key"
                    :class="cn('rounded-xl border bg-card shadow-sm transition-colors',
                        row.status === 'failed' ? 'border-red-300 dark:border-red-900'
                            : row.status === 'done' ? 'border-emerald-300 dark:border-emerald-900' : 'border-border')"
                >
                    <!-- Qui -->
                    <div class="flex items-center gap-3 px-4 pt-4">
                        <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold', avatarTone(row.employee.uuid))" aria-hidden="true">{{ initialsOf({ name: row.employee.name }) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground">{{ row.employee.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ who(row.employee) }}</p>
                            <p v-if="!singleSite" class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground/80"><Building2 class="h-3 w-3" aria-hidden="true" />{{ row.employee.site_name }}</p>
                        </div>
                        <Badge v-if="row.status === 'running'" variant="outline" class="shrink-0"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />En cours</Badge>
                        <Badge v-else-if="row.status === 'done'" variant="success" class="shrink-0"><Check class="h-3.5 w-3.5" aria-hidden="true" />Créé</Badge>
                        <Badge v-else-if="row.status === 'failed'" variant="destructive" class="shrink-0"><CircleAlert class="h-3.5 w-3.5" aria-hidden="true" />Refusé</Badge>
                        <Badge v-else-if="!problemOf(row)" variant="success" class="shrink-0"><CircleCheck class="h-3.5 w-3.5" aria-hidden="true" />Prêt</Badge>
                        <Badge v-else variant="outline" class="shrink-0">À compléter</Badge>
                    </div>

                    <!-- Saisie -->
                    <div v-if="row.status === 'idle' || row.status === 'failed'" class="grid gap-x-4 gap-y-3 p-4 md:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-1.5">
                            <FormField label="Adresse professionnelle" :as="hasActiveMailbox(row.employee) ? 'div' : 'label'">
                                <div v-if="hasActiveMailbox(row.employee)" class="flex h-[var(--control-h)] min-w-0 items-center gap-2 rounded-lg border border-dashed border-border bg-muted/40 px-3">
                                    <AtSign class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    <span class="truncate font-mono text-sm" :title="row.employee.mailbox.address">{{ row.employee.mailbox.address }}</span>
                                    <Badge variant="outline" class="ms-auto shrink-0 py-0 text-[10px]">déjà ouverte</Badge>
                                </div>
                                <div
                                    v-else
                                    class="flex h-[var(--control-h)] min-w-0 items-center overflow-hidden rounded-lg border border-input bg-background shadow-sm transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30"
                                >
                                    <AtSign class="ms-3 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    <input
                                        v-model="row.local_part"
                                        class="h-full min-w-0 flex-1 border-0 bg-transparent px-2 font-mono text-sm text-foreground outline-none placeholder:text-muted-foreground focus:ring-0"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="prenom.nom"
                                        :aria-label="`Adresse de ${row.employee.name}`"
                                        :disabled="phase === 'running'"
                                        @input="row.touched = true"
                                    >
                                    <span class="flex h-full max-w-[50%] shrink-0 items-center truncate border-s border-border bg-muted px-3 font-mono text-sm text-muted-foreground">@{{ domain ?? '…' }}</span>
                                </div>
                            </FormField>
                            <p v-if="hasActiveMailbox(row.employee)" class="text-xs text-muted-foreground">Elle recevra le mot de passe que l’employé choisira pour RIVO.</p>
                        </div>

                        <div class="grid min-w-0 content-start gap-3">
                            <FormField label="Rôle dans RIVO" as="div">
                                <template v-if="fromJobTitle(row)" #action>
                                    <span class="ms-auto inline-flex items-center gap-1 text-[11px] font-medium text-primary" :title="`Proposé par sa fonction « ${row.employee.proposed_access.job_title} » — modifiable`">
                                        <Sparkles class="h-3 w-3" aria-hidden="true" />selon sa fonction
                                    </span>
                                </template>
                                <Select :model-value="row.role_id" :options="roleOptions(row.site)" class="w-full" :aria-label="`Rôle de ${row.employee.name}`" :disabled="phase === 'running'" @update:model-value="setRole(row, $event)" />
                            </FormField>
                            <FormField v-if="profilesOf(row.site, row.role_id).length" label="Profil métier" as="div">
                                <Select v-model="row.profile_id" :options="profileOptions(row.site, row.role_id)" class="w-full" :aria-label="`Profil de ${row.employee.name}`" :disabled="phase === 'running'" @update:model-value="row.touched = true" />
                            </FormField>
                            <p v-if="!row.proposal.fromJobTitle && !row.role_id" class="text-xs text-muted-foreground">
                                Sa fonction ne propose aucun rôle : choisissez-le. Pour la suite, réglez-le une fois dans RH › Fonctions.
                            </p>
                        </div>

                        <p v-if="row.status === 'failed'" class="md:col-span-2 flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-300">
                            <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ row.message }}
                        </p>
                        <p v-else-if="visibleProblem(row)" class="md:col-span-2 flex items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ visibleProblem(row) }}
                        </p>
                        <Button
                            v-if="row.status === 'failed' && phase === 'done'"
                            type="button"
                            size="sm"
                            variant="white-outline"
                            class="justify-self-start md:col-span-2"
                            :disabled="Boolean(problemOf(row))"
                            @click="retry(row)"
                        >
                            <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />Réessayer
                        </Button>
                    </div>

                    <!-- Avancement -->
                    <p v-else-if="row.status === 'running'" class="flex items-center gap-2 px-4 pb-4 pt-3 text-sm text-muted-foreground">
                        <Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" />Création de l’adresse, puis du compte…
                    </p>

                    <!-- Résultat : l'adresse ; le mot de passe, l'employé le choisira -->
                    <div v-else-if="row.status === 'done' && row.result" class="grid gap-2 p-4 md:grid-cols-2">
                        <div class="flex min-w-0 items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2">
                            <AtSign class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate font-mono text-sm" :title="row.result.email">{{ row.result.email }}</span>
                            <Button type="button" size="xs" variant="ghost" icon :aria-label="`Copier l’adresse de ${row.employee.name}`" @click="copy(row.result.email)"><Copy class="h-3.5 w-3.5" /></Button>
                        </div>
                        <p class="flex min-w-0 items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                            <KeyRound class="h-4 w-4 shrink-0" aria-hidden="true" />En attente de sa première connexion : il choisira son mot de passe.
                        </p>
                    </div>
                </li>
            </ul>

            <!-- Envoi au RH -->
            <div v-if="phase === 'done' && doneBySite.length" class="space-y-3 border-t border-border bg-muted/30 px-6 py-4">
                <div v-for="group in doneBySite" :key="group.uuid" class="rounded-xl border border-border bg-card p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Send class="h-4 w-4" aria-hidden="true" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground">{{ group.rows.length }} accès à annoncer · {{ group.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="sent[group.uuid]">{{ sent[group.uuid] }}</template>
                                <template v-else-if="receiversOf(group.site) > 0">{{ receiversOf(group.site) }} personne{{ receiversOf(group.site) > 1 ? 's' : '' }} du site recevr{{ receiversOf(group.site) > 1 ? 'ont' : 'a' }} la notification et préviendr{{ receiversOf(group.site) > 1 ? 'ont' : 'a' }} les employés.</template>
                                <template v-else>Désignez d’abord qui préviendra les employés au site, ci-dessous.</template>
                            </p>
                        </div>
                        <Button v-if="!sent[group.uuid]" type="button" size="sm" :disabled="sending[group.uuid] || receiversOf(group.site) === 0" @click="send(group)">
                            <Loader2 v-if="sending[group.uuid]" class="h-4 w-4 animate-spin" aria-hidden="true" />
                            <Send v-else class="h-4 w-4" aria-hidden="true" />Envoyer au RH
                        </Button>
                        <Badge v-else variant="success" class="self-start sm:self-auto"><Check class="h-3.5 w-3.5" />Envoyé</Badge>
                    </div>
                    <StaffAccessReceiverPicker
                        v-if="!sent[group.uuid] && receiversOf(group.site) === 0"
                        class="mt-3"
                        :site="group.site"
                        :site-name="group.name"
                        :accounts="accountsOf(group.site)"
                        :can-designate="canDesignate"
                        @designated="onDesignated(group.site, $event)"
                    />
                </div>
                <p class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                    Aucun mot de passe n’est créé ni transmis : chaque employé choisit le sien en tapant son adresse sur la page de connexion, dans les {{ activationDays }} jours.
                </p>
            </div>
        </div>

        <template #footer>
            <template v-if="phase === 'form'">
                <p class="me-auto self-center text-xs text-muted-foreground" aria-live="polite">
                    <template v-if="incomplete.length">{{ incomplete.length }} employé{{ incomplete.length > 1 ? 's' : '' }} à compléter</template>
                    <template v-else>{{ rows.length }} accès prêt{{ rows.length > 1 ? 's' : '' }}</template>
                </p>
                <Button type="button" variant="outline" @click="close(false)">Annuler</Button>
                <Button type="button" :disabled="!ready" @click="run">
                    <UserPlus class="h-4 w-4" aria-hidden="true" />{{ rows.length > 1 ? `Créer ${rows.length} accès` : 'Créer l’accès' }}
                </Button>
            </template>
            <template v-else>
                <p v-if="failed.length && phase === 'done'" class="me-auto self-center text-xs font-medium text-red-700 dark:text-red-400">{{ failed.length }} accès non créé{{ failed.length > 1 ? 's' : '' }} : corrigez puis réessayez.</p>
                <Button type="button" variant="outline" :disabled="running" @click="close(false)">Fermer</Button>
            </template>
        </template>
    </Dialog>
</template>
