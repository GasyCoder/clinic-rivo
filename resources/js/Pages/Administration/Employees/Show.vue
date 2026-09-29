<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive, ArchiveRestore, ArrowLeft, ArrowRight, AtSign, Briefcase, Building2, CalendarClock, CalendarDays, CalendarPlus, Clock,
    Copy, Download, Eye, FileText, FileUp, Fingerprint, GraduationCap, IdCard, KeyRound, Mail, MapPin, NotebookPen, Pencil,
    Phone, Plus, Printer, Shirt, Sparkles, Upload, User, UserRound, Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import ProfessionalMailboxCard from '@/Components/Administration/ProfessionalMailboxCard.vue';
import EmployeePayrollCard from '@/Components/Administration/EmployeePayrollCard.vue';
import EmployeeBadgeCard from '@/Components/Administration/EmployeeBadgeCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';
import { employeeAccountLink } from '@/utilities/employeeAccount';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { composeHref } from '@/utilities/webmail';

/*
 * ADR-066 / ADR-194 — le dossier d'une personne : identité, coordonnées,
 * congés, présences, planning, contrats et pièces privées. Chaque bloc n'est
 * servi qu'avec le droit qui possède sa donnée ; un bloc absent n'est jamais
 * affiché vide (ADR-102). Servi aussi au portail (ADR-187) : toute adresse RH
 * passe par hrUrl().
 */
defineOptions({ layout: AppLayout });
const props = defineProps({
    employee: { type: Object, required: true },
    contracts: { type: [Array, Object], default: () => [] },
    documents: { type: [Array, Object], default: () => [] },
    leave: { type: Object, default: null },
    attendance: { type: Array, default: null },
    planning: { type: Array, default: null },
    documentOptions: { type: Array, default: () => [] },
    attestationTypes: { type: Array, default: () => [] },
    professionalEmail: { type: Object, default: null },
    // ADR-206 — servie seulement avec employees.payroll.view.
    payroll: { type: Object, default: null },
    // ADR-221 — avantages et primes, confidentiels comme la rémunération.
    benefits: { type: Array, default: null },
    // ADR-209 — le badge du personnel, tel qu'il s'imprime ; absent pour un dossier archivé.
    badge: { type: Object, default: null },
});

const page = usePage();
const toast = useToastStore();
const { can } = usePermissions();
// ADR-188 — le compte de connexion : le créer, ou l'ouvrir, depuis la fiche.
const accountLink = employeeAccountLink(props.employee, can, hrContext());

// --- Lecture --------------------------------------------------------------
const DATE = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' });
const DAY = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
const TIME = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });
const formatDate = (value) => (value ? DATE.format(new Date(`${value}T00:00:00`)) : null);
const formatDay = (iso) => (iso ? DAY.format(new Date(iso)) : '—');
const formatTime = (iso) => (iso ? TIME.format(new Date(iso)) : '—');
const formatBytes = (value) => (value >= 1048576 ? `${(value / 1048576).toFixed(1)} Mo` : `${Math.max(1, Math.round(value / 1024))} Ko`);
const DAYS = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
const days = (value) => (value == null || value === '' ? '—' : DAYS.format(Number(value)));
const formatMinutes = (minutes) => (minutes == null ? 'En cours' : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`);

/** L'ancienneté lue sur la date d'entrée : « 2 ans et 3 mois », « 5 mois », « arrivé ce mois-ci ». */
const seniority = computed(() => {
    // Calculée par le serveur depuis la date d'entrée quand il la sert.
    if (props.employee.seniority?.label) return props.employee.seniority.label;
    if (!props.employee.hire_date) return null;
    const start = new Date(`${props.employee.hire_date}T00:00:00`);
    const now = new Date();
    if (start > now) return 'Arrivée à venir';
    let months = (now.getFullYear() - start.getFullYear()) * 12 + (now.getMonth() - start.getMonth());
    if (now.getDate() < start.getDate()) months -= 1;
    const years = Math.floor(months / 12);
    const rest = months % 12;
    if (years === 0 && rest === 0) return 'Arrivé ce mois-ci';
    const parts = [];
    if (years) parts.push(`${years} an${years > 1 ? 's' : ''}`);
    if (rest) parts.push(`${rest} mois`);
    return parts.join(' et ');
});

const status = computed(() => {
    if (props.employee.archived) return { label: 'Archivé', tone: 'neutral' };
    return props.employee.active ? { label: 'Actif', tone: 'success' } : { label: 'Inactif', tone: 'warning' };
});
const IDENTITY_TYPES = { CIN: 'CIN', PASSPORT: 'Passeport' };
const identityDocument = computed(() => {
    const type = props.employee.identity_document_type;
    const number = props.employee.identity_document_number;
    if (!type && !number) return null;
    return [IDENTITY_TYPES[type] ?? type, number].filter(Boolean).join(' ');
});
const identityIssued = computed(() => [formatDate(props.employee.identity_document_issued_on), props.employee.identity_document_issued_at].filter(Boolean).join(' · ') || null);

const identityFacts = computed(() => [
    { key: 'birth', icon: CalendarDays, label: 'Naissance', value: [formatDate(props.employee.birth_date), props.employee.birth_place].filter(Boolean).join(' · ') || null },
    { key: 'sex', icon: UserRound, label: 'Genre', value: props.employee.sex === 'M' ? 'Homme' : props.employee.sex === 'F' ? 'Femme' : null },
    { key: 'id', icon: Fingerprint, label: 'Pièce d’identité', value: identityDocument.value },
    { key: 'issued', icon: CalendarClock, label: 'Délivrée', value: identityIssued.value },
    { key: 'diploma', icon: GraduationCap, label: 'Diplôme / niveau', value: [props.employee.diploma, props.employee.education_level].filter(Boolean).join(' · ') || null },
    { key: 'children', icon: Users, label: 'Enfants', value: props.employee.children_count != null ? String(props.employee.children_count) : null },
    { key: 'children_details', icon: NotebookPen, label: 'Détails des enfants', value: props.employee.children_details, wide: true, multiline: true },
]);
const contactFacts = computed(() => [
    { key: 'phone', icon: Phone, label: 'Téléphone', value: props.employee.phone, href: props.employee.phone ? `tel:${props.employee.phone.replace(/\s+/g, '')}` : null, copy: true },
    { key: 'email', icon: AtSign, label: 'Email', value: props.employee.email, href: props.employee.email ? `mailto:${props.employee.email}` : null, copy: true },
    { key: 'address', icon: MapPin, label: 'Adresse', value: props.employee.address, wide: true },
    { key: 'badge', icon: IdCard, label: 'N° de badge', value: props.employee.badge },
    { key: 'blouse', icon: Shirt, label: 'Blouse', value: props.employee.blouse },
    { key: 'observation', icon: NotebookPen, label: 'Observation', value: props.employee.observation, wide: true, multiline: true, hideEmpty: true },
]);

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copié.');
    } catch {
        toast.error('Copie impossible : sélectionnez le texte.');
    }
};

// --- Écrire : la messagerie de RIVO quand le compte en a une, sinon la sienne ---
const webmailAvailable = computed(() => Boolean(page.props.webmail?.available));
const emailHref = computed(() => {
    if (!props.employee.email) return null;
    return webmailAvailable.value ? composeHref(props.employee.name, props.employee.email) : `mailto:${props.employee.email}`;
});
const emailTitle = computed(() => {
    if (!props.employee.email) return 'Aucune adresse email : elle se crée avec son accès (« Accès du personnel »).';
    return webmailAvailable.value ? `Écrire à ${props.employee.email} depuis la messagerie RIVO` : `Écrire à ${props.employee.email}`;
});

// --- Congés, présences, planning ------------------------------------------
const LEAVE_TONES = { PENDING: 'warning', APPROVED: 'success', REJECTED: 'danger', CANCELLED: 'neutral' };
const balancePercent = (balance) => {
    const quota = Number(balance.annual_quota_days) || 0;
    return quota > 0 ? Math.min(100, Math.round((Number(balance.approved_days) / quota) * 100)) : 0;
};
const pendingPercent = (balance) => {
    const quota = Number(balance.annual_quota_days) || 0;
    return quota > 0 ? Math.min(100 - balancePercent(balance), Math.round((Number(balance.pending_days) / quota) * 100)) : 0;
};

// --- Actions sur le dossier -----------------------------------------------
const archiveOpen = ref(false);
const archiveForm = useForm({ reason: '' });
const archiveEmployee = () => archiveForm.delete(hrUrl(`/administration/employees/${props.employee.uuid}`), {
    onSuccess: () => { archiveOpen.value = false; archiveForm.reset(); },
});
const restoreEmployee = () => router.post(hrUrl(`/administration/employees/${props.employee.uuid}/restore`));

const quickActions = computed(() => [
    { key: 'contract', permission: 'contracts.create', icon: FileText, label: 'Nouveau contrat', hint: 'Type, dates et référence', href: hrUrl(`/administration/contracts/create?employee=${props.employee.uuid}`) },
    { key: 'attendance', permission: 'attendance.create', icon: Clock, label: 'Saisir une présence', hint: 'Entrée et sortie du jour', href: hrUrl(`/administration/attendance/create?employee=${props.employee.uuid}`) },
    { key: 'leave', permission: 'leave.create', icon: CalendarDays, label: 'Demande de congé', hint: 'Durée et solde calculés', href: hrUrl(`/administration/leave/create?employee=${props.employee.uuid}`) },
    { key: 'planning', permission: 'planning.create', icon: CalendarPlus, label: 'Ajouter au planning', hint: 'Service ou garde', href: hrUrl(`/administration/planning/create?employee=${props.employee.uuid}`) },
].filter((action) => can(action.permission) && !props.employee.archived));

// --- Pièces privées ---------------------------------------------------------
const documentArchiveTarget = ref(null);
const documentArchiveForm = useForm({ reason: '' });
const openDocumentArchive = (document) => { documentArchiveTarget.value = document; documentArchiveForm.reset(); };
const archiveDocument = () => documentArchiveForm.delete(hrUrl(`/administration/documents/${documentArchiveTarget.value.uuid}`), {
    onSuccess: () => { documentArchiveTarget.value = null; documentArchiveForm.reset(); },
});
const restoreDocument = (document) => router.post(hrUrl(`/administration/documents/${document.uuid}/restore`));

const uploadForm = useForm({
    employee_uuid: props.employee.uuid, category: 'PERSONNEL', title: '', issued_on: '', notes: '',
    attestation_type_uuid: '', employment_contract_uuid: '', leave_request_uuid: '', file: null,
});
const fileInput = ref(null);
const fileDragging = ref(false);
const categoryOptions = computed(() => props.documentOptions.map((item) => ({ value: item.value, label: item.label })));
const attestationOptions = computed(() => props.attestationTypes.map((item) => ({ value: item.uuid, label: item.label })));
const contractOptions = computed(() => [
    { value: '', label: 'Sans association' },
    ...props.contracts.filter((item) => !item.archived).map((item) => ({ value: item.uuid, label: `${item.contract_type} · ${formatDate(item.starts_on) ?? '—'}` })),
]);
const pickFile = (file) => {
    if (!file) return;
    uploadForm.file = file;
    if (!uploadForm.title) uploadForm.title = file.name.replace(/\.[^.]+$/, '');
};
const onFileDrop = (event) => { fileDragging.value = false; pickFile(event.dataTransfer?.files?.[0]); };
const uploadDocument = () => uploadForm.post(hrUrl('/administration/documents'), {
    forceFormData: true,
    onSuccess: () => {
        uploadForm.reset('title', 'issued_on', 'notes', 'attestation_type_uuid', 'employment_contract_uuid', 'leave_request_uuid', 'file');
        if (fileInput.value) fileInput.value.value = '';
    },
});

const DOCUMENT_ICONS = { 'application/pdf': FileText };
const documentIcon = (document) => DOCUMENT_ICONS[document.mime_type] ?? (document.mime_type?.startsWith('image/') ? Eye : FileText);
</script>

<template>
    <Head :title="employee.name" />

    <div class="w-full space-y-4">
        <Link :href="hrUrl('/administration/employees')" class="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <ArrowLeft class="h-4 w-4" />Annuaire du personnel
        </Link>

        <!-- En-tête du dossier -->
        <Card class="overflow-hidden p-0">
            <div class="relative h-24 overflow-hidden bg-gradient-to-r from-primary via-primary/85 to-primary/55 sm:h-28" aria-hidden="true">
                <span class="absolute -right-10 -top-16 h-52 w-52 rounded-full bg-white/10" />
                <span class="absolute right-40 top-10 h-24 w-24 rounded-full bg-white/10" />
                <span class="absolute -bottom-20 left-1/3 h-40 w-40 rounded-full bg-white/5" />
            </div>
            <div class="px-4 pb-5 sm:px-6">
                <!-- Seule la photo déborde sur le bandeau : le texte reste dessous, lisible. -->
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:gap-5">
                        <EmployeePhoto :src="employee.photo_url" :name="employee.name" size="xl" class="-mt-12 h-24 w-24 shadow-lg ring-4 ring-card sm:-mt-14 sm:h-28 sm:w-28" />
                        <div class="min-w-0 sm:pt-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge variant="outline" class="font-mono">{{ employee.employee_number }}</Badge>
                                <Badge :tone="status.tone">{{ status.label }}</Badge>
                                <Badge v-if="employee.user_account" variant="secondary" :title="employee.user_account.active ? 'Compte RIVO relié à ce dossier' : 'Compte RIVO relié, désactivé'">
                                    <KeyRound class="h-3 w-3" />Compte RIVO<template v-if="!employee.user_account.active"> · désactivé</template>
                                </Badge>
                            </div>
                            <h1 class="mt-1.5 break-words font-heading text-2xl font-bold leading-tight text-foreground sm:text-3xl">{{ employee.name }}</h1>
                            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <span class="inline-flex items-center gap-1.5"><Briefcase class="h-4 w-4" />{{ employee.job_title || 'Fonction non renseignée' }}</span>
                                <span v-if="employee.department" class="inline-flex items-center gap-1.5"><Building2 class="h-4 w-4" />{{ employee.department }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:shrink-0 lg:pt-3">
                        <Button
                            v-if="emailHref"
                            :as="webmailAvailable ? Link : 'a'"
                            :href="emailHref"
                            variant="outline"
                            icon
                            :title="emailTitle"
                            :aria-label="emailTitle"
                        ><Mail class="h-4 w-4" /></Button>
                        <span v-else class="inline-flex" :title="emailTitle">
                            <Button type="button" variant="outline" icon disabled :aria-label="emailTitle"><Mail class="h-4 w-4" /></Button>
                        </span>
                        <Button v-if="badge && can('employees.print')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/badge`)" variant="outline"><IdCard class="h-4 w-4" />Badge</Button>
                        <Button v-if="can('employees.print')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/print`)" target="_blank" variant="outline"><Printer class="h-4 w-4" />Fiche</Button>
                        <Button v-if="!employee.archived && can('employees.update')" :as="Link" :href="hrUrl(`/administration/employees/${employee.uuid}/edit`)"><Pencil class="h-4 w-4" />Modifier</Button>
                        <Button v-if="employee.archived && can('employees.restore')" type="button" variant="success" @click="restoreEmployee"><ArchiveRestore class="h-4 w-4" />Restaurer</Button>
                    </div>
                </div>

                <!-- L'essentiel, d'un coup d'œil -->
                <dl class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><CalendarDays class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Date d’entrée</dt>
                            <dd class="truncate text-sm font-semibold text-foreground">{{ formatDate(employee.hire_date) ?? 'Non renseignée' }}</dd>
                            <dd v-if="seniority" class="truncate text-xs text-muted-foreground">{{ seniority }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Phone class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Téléphone</dt>
                            <dd class="truncate text-sm font-semibold text-foreground">
                                <a v-if="employee.phone" :href="`tel:${employee.phone.replace(/\s+/g, '')}`" class="hover:text-primary hover:underline">{{ employee.phone }}</a>
                                <span v-else class="font-normal text-muted-foreground">Non renseigné</span>
                            </dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400"><AtSign class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Email professionnel</dt>
                            <dd class="truncate text-sm font-semibold text-foreground" :title="employee.email || undefined">
                                <component :is="webmailAvailable ? Link : 'a'" v-if="emailHref" :href="emailHref" class="hover:text-primary hover:underline">{{ employee.email }}</component>
                                <span v-else class="font-normal text-muted-foreground">Aucune adresse</span>
                            </dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400"><KeyRound class="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Compte RIVO</dt>
                            <dd class="truncate text-sm font-semibold text-foreground" :title="employee.user_account ? 'Le planning RH de cette fiche vaut pour ce compte (ADR-168).' : 'Le compte se relie depuis « Utilisateurs » ou « Accès du personnel ».'">
                                {{ employee.user_account?.name ?? 'Aucun compte relié' }}
                            </dd>
                            <dd v-if="employee.user_account" class="text-xs" :class="employee.user_account.active ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ employee.user_account.active ? 'Actif' : 'Désactivé' }}</dd>
                            <dd v-if="accountLink"><Link :href="accountLink.href" class="text-xs font-semibold text-primary hover:underline" :data-account-link="accountLink.kind">{{ accountLink.label }} →</Link></dd>
                        </div>
                    </div>
                </dl>
            </div>
        </Card>

        <div v-if="employee.archived" class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm">
            <Archive class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="font-semibold text-foreground">Dossier archivé</p>
                <p class="text-xs text-muted-foreground">{{ employee.delete_reason || 'Motif non renseigné' }} — l’historique reste consultable ; restaurer le rend de nouveau modifiable.</p>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-4">
                <!-- Identité et coordonnées -->
                <div class="grid gap-4 2xl:grid-cols-2">
                    <Card class="p-5">
                        <header class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-primary/10 text-primary"><User class="h-4 w-4" /></span>
                            <h2 class="font-heading text-base font-bold text-foreground">Identité administrative</h2>
                        </header>
                        <dl class="mt-4 grid gap-x-5 gap-y-3.5 sm:grid-cols-2">
                            <div v-for="fact in identityFacts" :key="fact.key" :class="cn('flex min-w-0 gap-2.5', fact.wide && 'sm:col-span-2')">
                                <component :is="fact.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                <div class="min-w-0">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ fact.label }}</dt>
                                    <dd :class="cn('text-sm text-foreground', fact.multiline && 'whitespace-pre-line', !fact.value && 'text-muted-foreground')">{{ fact.value || 'Non renseigné' }}</dd>
                                </div>
                            </div>
                        </dl>
                    </Card>

                    <Card class="p-5">
                        <header class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Phone class="h-4 w-4" /></span>
                            <h2 class="font-heading text-base font-bold text-foreground">Coordonnées et équipement</h2>
                        </header>
                        <dl class="mt-4 grid gap-x-5 gap-y-3.5 sm:grid-cols-2">
                            <template v-for="fact in contactFacts" :key="fact.key">
                                <div v-if="fact.value || !fact.hideEmpty" :class="cn('flex min-w-0 gap-2.5', fact.wide && 'sm:col-span-2')">
                                    <component :is="fact.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                    <div class="min-w-0 flex-1">
                                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ fact.label }}</dt>
                                        <dd :class="cn('flex min-w-0 items-center gap-1 text-sm text-foreground', fact.multiline && 'whitespace-pre-line', !fact.value && 'text-muted-foreground')">
                                            <a v-if="fact.value && fact.href" :href="fact.href" class="truncate hover:text-primary hover:underline">{{ fact.value }}</a>
                                            <span v-else class="truncate">{{ fact.value || 'Non renseigné' }}</span>
                                            <button
                                                v-if="fact.value && fact.copy"
                                                type="button"
                                                class="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                :aria-label="`Copier : ${fact.label}`"
                                                :title="`Copier : ${fact.label}`"
                                                @click="copy(fact.value)"
                                            ><Copy class="h-3.5 w-3.5" /></button>
                                        </dd>
                                    </div>
                                </div>
                            </template>
                        </dl>
                    </Card>
                </div>

                <!-- Congés : soldes de l'année et dernières demandes -->
                <Card v-if="leave" class="overflow-hidden p-0">
                    <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"><CalendarDays class="h-4 w-4" /></span>
                            <div>
                                <h2 class="font-heading text-base font-bold text-foreground">Congés</h2>
                                <p class="text-xs text-muted-foreground">Soldes de l’année et dernières demandes.</p>
                            </div>
                        </div>
                        <Button :as="Link" :href="hrUrl('/administration/leave?status=ALL')" variant="ghost" size="sm">Tout voir<ArrowRight class="h-4 w-4" /></Button>
                    </header>
                    <div v-if="leave.balances?.length" class="grid gap-3 border-b border-border p-5 sm:grid-cols-2">
                        <div v-for="balance in leave.balances" :key="balance.leave_type" class="rounded-xl border border-border bg-muted/25 p-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-foreground">{{ balance.leave_type }}</p>
                                <p class="shrink-0 text-xs text-muted-foreground">{{ balance.year }}</p>
                            </div>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-foreground">{{ days(balance.balance) }}<span class="ms-1 text-sm font-medium text-muted-foreground">/ {{ days(balance.annual_quota_days) }} j restants</span></p>
                            <div class="mt-2 flex h-2 overflow-hidden rounded-full bg-muted" role="img" :aria-label="`${days(balance.approved_days)} jours pris, ${days(balance.pending_days)} en attente, sur ${days(balance.annual_quota_days)}`">
                                <span class="h-full bg-primary" :style="{ width: `${balancePercent(balance)}%` }" />
                                <span class="h-full bg-amber-400" :style="{ width: `${pendingPercent(balance)}%` }" />
                            </div>
                            <p class="mt-1.5 flex flex-wrap gap-x-3 text-[11px] text-muted-foreground">
                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-primary" />{{ days(balance.approved_days) }} j pris</span>
                                <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-400" />{{ days(balance.pending_days) }} j en attente</span>
                            </p>
                        </div>
                    </div>
                    <ul v-if="leave.recent?.length" class="divide-y divide-border">
                        <li v-for="request in leave.recent" :key="request.uuid" class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-foreground">{{ request.leave_type || 'Congé' }} · {{ days(request.days_requested) }} j</p>
                                <p class="text-xs text-muted-foreground">Du {{ formatDate(request.starts_on) }} — retour le {{ formatDate(request.returns_on) }}</p>
                            </div>
                            <Badge :tone="LEAVE_TONES[request.status] ?? 'neutral'" class="self-start sm:self-auto">{{ request.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-6 text-center text-sm text-muted-foreground">Aucune demande de congé.</p>
                </Card>

                <!-- Planning à venir et présences récentes -->
                <div v-if="planning || attendance" class="grid gap-4 2xl:grid-cols-2">
                    <Card v-if="planning" class="overflow-hidden p-0">
                        <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400"><CalendarClock class="h-4 w-4" /></span>
                                <h2 class="font-heading text-base font-bold text-foreground">Planning à venir</h2>
                            </div>
                            <Button :as="Link" :href="hrUrl('/administration/planning')" variant="ghost" size="sm">Planning<ArrowRight class="h-4 w-4" /></Button>
                        </header>
                        <ul v-if="planning.length" class="divide-y divide-border">
                            <li v-for="shift in planning" :key="shift.uuid" class="flex items-center gap-3 px-5 py-3">
                                <span class="w-20 shrink-0 text-xs font-semibold capitalize text-foreground">{{ formatDay(shift.starts_at) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-foreground">{{ shift.title || shift.department || 'Créneau' }}</p>
                                    <p class="text-xs tabular-nums text-muted-foreground">{{ formatTime(shift.starts_at) }} – {{ formatTime(shift.ends_at) }}</p>
                                </div>
                                <Badge :variant="shift.kind === 'ON_CALL' ? 'secondary' : 'outline'">{{ shift.kind_label }}</Badge>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-6 text-center text-sm text-muted-foreground">Aucun créneau à venir.</p>
                    </Card>

                    <Card v-if="attendance" class="overflow-hidden p-0">
                        <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Clock class="h-4 w-4" /></span>
                                <h2 class="font-heading text-base font-bold text-foreground">Présences récentes</h2>
                            </div>
                            <Button :as="Link" :href="hrUrl(`/administration/attendance?employee=${employee.uuid}`)" variant="ghost" size="sm">Tout voir<ArrowRight class="h-4 w-4" /></Button>
                        </header>
                        <ul v-if="attendance.length" class="divide-y divide-border">
                            <li v-for="record in attendance" :key="record.uuid" class="flex items-center gap-3 px-5 py-3">
                                <span class="w-20 shrink-0 text-xs font-semibold capitalize text-foreground">{{ formatDay(record.started_at) }}</span>
                                <p class="min-w-0 flex-1 text-xs tabular-nums text-muted-foreground">{{ formatTime(record.started_at) }} → {{ record.ended_at ? formatTime(record.ended_at) : '…' }}</p>
                                <Badge :tone="record.ended_at ? 'neutral' : 'success'">{{ formatMinutes(record.minutes) }}</Badge>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-6 text-center text-sm text-muted-foreground">Aucune présence saisie.</p>
                    </Card>
                </div>

                <!-- Contrats -->
                <Card v-if="can('contracts.view')" class="overflow-hidden p-0">
                    <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400"><FileText class="h-4 w-4" /></span>
                            <div>
                                <h2 class="font-heading text-base font-bold text-foreground">Contrats <span class="text-sm font-medium text-muted-foreground">· {{ contracts.length }}</span></h2>
                                <p class="text-xs text-muted-foreground">Historique conservé, y compris après archivage.</p>
                            </div>
                        </div>
                        <Button v-if="can('contracts.create') && !employee.archived" :as="Link" :href="hrUrl(`/administration/contracts/create?employee=${employee.uuid}`)" size="sm"><Plus class="h-4 w-4" />Ajouter</Button>
                    </header>
                    <ul v-if="contracts.length" class="divide-y divide-border">
                        <li v-for="contract in contracts" :key="contract.uuid" class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-foreground">{{ contract.contract_type }}</p>
                                    <Badge v-if="contract.archived" variant="outline">Archivé</Badge>
                                    <Badge v-if="contract.internship" variant="secondary"><GraduationCap class="h-3 w-3" />Stage</Badge>
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ formatDate(contract.starts_on) }} → {{ contract.ends_on ? formatDate(contract.ends_on) : 'sans date de fin' }}<template v-if="contract.reference_number"> · {{ contract.reference_number }}</template></p>
                                <p v-if="contract.internship" class="mt-0.5 text-xs text-violet-700 dark:text-violet-300">
                                    {{ contract.internship.field || 'Filière à compléter' }}<template v-if="contract.internship.school"> · {{ contract.internship.school }}</template><template v-if="contract.internship.level"> · {{ contract.internship.level }}</template><template v-if="contract.internship.supervisor"> · encadré par {{ contract.internship.supervisor.name }}</template>
                                </p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <Button v-if="can('contracts.print')" :as="Link" :href="hrUrl(`/administration/contracts/${contract.uuid}/print`)" target="_blank" variant="outline" icon title="Imprimer le contrat" aria-label="Imprimer le contrat"><Printer class="h-4 w-4" /></Button>
                                <Button v-if="!contract.archived && can('contracts.update')" :as="Link" :href="hrUrl(`/administration/contracts/${contract.uuid}/edit`)" variant="outline" icon title="Modifier le contrat" aria-label="Modifier le contrat"><Pencil class="h-4 w-4" /></Button>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-8 text-center text-sm text-muted-foreground">Aucun contrat enregistré.</p>
                </Card>

                <!-- Pièces privées -->
                <Card v-if="can('hr_documents.view')" class="overflow-hidden p-0">
                    <header class="flex items-center gap-3 border-b border-border px-5 py-4">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-muted text-muted-foreground"><FileUp class="h-4 w-4" /></span>
                        <div>
                            <h2 class="font-heading text-base font-bold text-foreground">Documents privés RH <span class="text-sm font-medium text-muted-foreground">· {{ documents.length }}</span></h2>
                            <p class="text-xs text-muted-foreground">Hors du répertoire public. PDF, images, Word ou Excel · 10 Mo au plus.</p>
                        </div>
                    </header>
                    <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <ul class="min-w-0 space-y-2">
                            <li v-for="document in documents" :key="document.uuid" class="flex items-center gap-3 rounded-xl border border-border p-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><component :is="documentIcon(document)" class="h-4 w-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <p class="truncate text-sm font-semibold text-foreground">{{ document.title }}</p>
                                        <Badge v-if="document.archived" variant="outline">Archivé</Badge>
                                    </div>
                                    <p class="truncate text-xs text-muted-foreground">{{ document.category_label }}<template v-if="document.attestation_type"> · {{ document.attestation_type }}</template> · {{ formatBytes(document.size) }}<template v-if="document.issued_on"> · {{ formatDate(document.issued_on) }}</template></p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <Button :as="Link" :href="hrUrl(`/administration/documents/${document.uuid}`)" target="_blank" variant="ghost" icon title="Ouvrir" aria-label="Ouvrir le document"><Eye class="h-4 w-4" /></Button>
                                    <Button :as="Link" :href="hrUrl(`/administration/documents/${document.uuid}/download`)" variant="ghost" icon title="Télécharger" aria-label="Télécharger le document"><Download class="h-4 w-4" /></Button>
                                    <Button v-if="!document.archived && can('hr_documents.archive')" type="button" variant="ghost" icon title="Archiver" aria-label="Archiver le document" @click="openDocumentArchive(document)"><Archive class="h-4 w-4" /></Button>
                                    <Button v-if="document.archived && can('hr_documents.restore')" type="button" variant="ghost" icon title="Restaurer" aria-label="Restaurer le document" @click="restoreDocument(document)"><ArchiveRestore class="h-4 w-4" /></Button>
                                </div>
                            </li>
                            <li v-if="documents.length === 0" class="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
                                <FileText class="mx-auto mb-2 h-6 w-6 opacity-60" />Aucun document dans ce dossier.
                            </li>
                        </ul>

                        <form v-if="can('hr_documents.create') && !employee.archived" class="space-y-3 rounded-xl border border-border bg-muted/25 p-4" @submit.prevent="uploadDocument">
                            <p class="flex items-center gap-2 text-sm font-semibold text-foreground"><Upload class="h-4 w-4 text-primary" />Ajouter une pièce</p>
                            <button
                                type="button"
                                :class="cn(
                                    'flex w-full flex-col items-center gap-1 rounded-lg border-2 border-dashed px-3 py-4 text-center text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    fileDragging ? 'border-primary bg-primary/10' : uploadForm.file ? 'border-emerald-400/70 bg-emerald-50/60 dark:bg-emerald-950/20' : 'border-border bg-card hover:border-primary/50 hover:bg-primary/5',
                                )"
                                @click="fileInput?.click()"
                                @dragover.prevent="fileDragging = true"
                                @dragleave.prevent="fileDragging = false"
                                @drop.prevent="onFileDrop"
                            >
                                <FileUp class="h-5 w-5 text-muted-foreground" />
                                <span v-if="uploadForm.file" class="max-w-full truncate font-semibold text-foreground">{{ uploadForm.file.name }}</span>
                                <span v-else class="font-semibold text-foreground">Choisir ou déposer un fichier</span>
                                <span class="text-muted-foreground">{{ uploadForm.file ? formatBytes(uploadForm.file.size) + ' · cliquer pour changer' : 'PDF, image, Word, Excel' }}</span>
                            </button>
                            <input ref="fileInput" type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" tabindex="-1" aria-hidden="true" @change="pickFile($event.target.files?.[0])">
                            <p v-if="uploadForm.errors.file" class="text-xs font-medium text-destructive">{{ uploadForm.errors.file }}</p>

                            <FormField as="div" label="Catégorie" :error="uploadForm.errors.category">
                                <ShadSelect v-model="uploadForm.category" :options="categoryOptions" aria-label="Catégorie" class="w-full" />
                            </FormField>
                            <FormField v-if="uploadForm.category === 'ATTESTATION'" as="div" label="Type d’attestation" required :error="uploadForm.errors.attestation_type_uuid" :hint="attestationTypes.length ? '' : 'Ajoutez d’abord un type dans Paramètres RH.'">
                                <ShadSelect v-model="uploadForm.attestation_type_uuid" :options="attestationOptions" placeholder="Sélectionner" aria-label="Type d’attestation" class="w-full" />
                            </FormField>
                            <FormField v-if="uploadForm.category === 'CONTRACT'" as="div" label="Contrat associé" :error="uploadForm.errors.employment_contract_uuid">
                                <ShadSelect v-model="uploadForm.employment_contract_uuid" :options="contractOptions" placeholder="Sans association" aria-label="Contrat associé" class="w-full" />
                            </FormField>
                            <FormField label="Titre" required :error="uploadForm.errors.title">
                                <Input v-model="uploadForm.title" required />
                            </FormField>
                            <FormField as="div" label="Date du document" :error="uploadForm.errors.issued_on">
                                <DatePicker v-model="uploadForm.issued_on" aria-label="Date du document" />
                            </FormField>
                            <FormField label="Notes" :error="uploadForm.errors.notes">
                                <Textarea v-model="uploadForm.notes" rows="2" />
                            </FormField>
                            <Button type="submit" class="w-full" :disabled="uploadForm.processing || !uploadForm.file"><Upload class="h-4 w-4" />{{ uploadForm.processing ? 'Envoi…' : 'Ajouter au dossier' }}</Button>
                        </form>
                    </div>
                </Card>
            </div>

            <!-- Colonne latérale -->
            <aside class="space-y-4">
                <!-- ADR-209 — le badge, tel qu'il s'imprime ; un dossier archivé n'en a plus. -->
                <EmployeeBadgeCard v-if="badge" :employee-uuid="employee.uuid" :badge="badge" :can-print="can('employees.print')" />

                <ProfessionalMailboxCard v-if="professionalEmail" :data="professionalEmail" :employee-uuid="employee.uuid" />
                <EmployeePayrollCard
                    v-if="payroll"
                    :payroll="payroll"
                    :benefits="benefits ?? []"
                    :edit-href="!employee.archived && can('employees.update') && can('employees.payroll.update') ? hrUrl(`/administration/employees/${employee.uuid}/edit?section=pay`) : null"
                    :benefits-href="!employee.archived && can('employees.update') && can('employees.payroll.update') ? hrUrl(`/administration/employees/${employee.uuid}/edit?section=benefits`) : null"
                />

                <Card v-if="quickActions.length" class="p-4">
                    <h2 class="flex items-center gap-2 font-heading text-base font-bold text-foreground"><Sparkles class="h-4 w-4 text-primary" />Actions rapides</h2>
                    <p class="text-xs text-muted-foreground">Créer une opération pour ce salarié.</p>
                    <div class="mt-3 grid gap-2">
                        <Link
                            v-for="action in quickActions"
                            :key="action.key"
                            :href="action.href"
                            class="group flex items-center gap-3 rounded-xl border border-border p-2.5 transition-colors hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary"><component :is="action.icon" class="h-4 w-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-foreground">{{ action.label }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ action.hint }}</span>
                            </span>
                            <ArrowRight class="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-primary" />
                        </Link>
                    </div>
                </Card>

                <Card v-if="!employee.archived && can('employees.delete')" class="border-red-200 p-4 dark:border-red-950">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-red-700 dark:text-red-300"><Archive class="h-4 w-4" />Archiver ce dossier</h2>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Rien n’est supprimé : le dossier reste restaurable et son historique est conservé.</p>
                    <Button type="button" size="sm" variant="danger-outline" class="mt-3 w-full" @click="archiveOpen = true"><Archive class="h-4 w-4" />Archiver…</Button>
                </Card>
            </aside>
        </div>
    </div>

    <!-- Archiver le dossier -->
    <ConfirmModal
        v-model:open="archiveOpen"
        title="Archiver ce dossier ?"
        description="Rien n’est supprimé : le dossier reste consultable, restaurable, et toutes ses références historiques sont conservées."
        confirm-label="Archiver le dossier"
        tone="danger"
        :icon="Archive"
        :processing="archiveForm.processing"
        :disabled="archiveForm.reason.trim().length === 0"
        @confirm="archiveEmployee"
    >
        <FormField label="Motif" required :error="archiveForm.errors.reason">
            <Textarea v-model="archiveForm.reason" rows="3" placeholder="Pourquoi ce dossier est archivé" />
        </FormField>
    </ConfirmModal>

    <!-- Archiver une pièce -->
    <ConfirmModal
        :open="documentArchiveTarget !== null"
        title="Archiver cette pièce ?"
        :description="documentArchiveTarget ? `« ${documentArchiveTarget.title} » quittera la liste ; elle reste restaurable.` : ''"
        confirm-label="Archiver la pièce"
        tone="danger"
        :icon="Archive"
        :processing="documentArchiveForm.processing"
        :disabled="documentArchiveForm.reason.trim().length === 0"
        @update:open="(value) => { if (!value) documentArchiveTarget = null; }"
        @confirm="archiveDocument"
    >
        <FormField label="Motif d’archivage" required :error="documentArchiveForm.errors.reason">
            <Textarea v-model="documentArchiveForm.reason" rows="3" />
        </FormField>
    </ConfirmModal>
</template>
