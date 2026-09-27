<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ArchiveRestore, ArrowRight, Award, BriefcaseBusiness, CalendarDays, FileSignature, FileText, GraduationCap,
    Network, Pencil, Plus, Settings,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ReferenceValueForm from './ReferenceValueForm.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/*
 * Les référentiels RH du site (ADR-066) : contrats, congés, attestations — et,
 * avec l'ADR-194, les filières de stage et le marquage « contrat de stage ».
 * Départements et fonctions ont leur propre module (ADR-188) : c'est là que se
 * règlent aussi les départements de chaque fonction.
 */
const props = defineProps({ references: Object, types: [Array, Object], leaveDayCountMethods: [Array, Object] });
const { can } = usePermissions();

const TYPES = {
    CONTRACT_TYPE: { icon: FileSignature, title: 'Contrats', description: 'Proposés à la création d’un contrat. Un type marqué « contrat de stage » ouvre la saisie du stage.' },
    LEAVE_TYPE: { icon: CalendarDays, title: 'Congés et permissions', description: 'Les règles de décompte, de quota, de justificatif et de validation des nouvelles demandes.' },
    ATTESTATION_TYPE: { icon: Award, title: 'Documents RH', description: 'Proposés au classement d’une attestation dans le dossier privé d’un employé.' },
    INTERNSHIP_FIELD: { icon: GraduationCap, title: 'Filières de stage', description: 'Les stages que la clinique accueille : infirmier, sage-femme, laboratoire… Chaque stage porte sa filière.' },
};

const selectedType = ref(props.types?.[0]?.value ?? 'CONTRACT_TYPE');
const items = computed(() => props.references?.[selectedType.value] ?? []);
const meta = computed(() => TYPES[selectedType.value] ?? { icon: Settings, title: '', description: '' });
const typeLabel = computed(() => props.types.find((type) => type.value === selectedType.value)?.label);
const activeCount = computed(() => items.value.filter((item) => !item.archived && item.active).length);
const archivedCount = computed(() => items.value.filter((item) => item.archived).length);

// ADR-188 — les deux référentiels de structure, chacun dans son module.
const STRUCTURE_MODULES = [
    { href: hrUrl('/administration/departments'), label: 'Départements', hint: 'Services de la clinique', icon: Network },
    { href: hrUrl('/administration/job-titles'), label: 'Fonctions', hint: 'Postes, métiers et leurs départements', icon: BriefcaseBusiness },
];

const defaultLeaveRules = () => ({ consumes_annual_balance: false, annual_quota_days: null, max_days_per_request: null, requires_attachment: false, requires_approval: true, day_count_method: 'CALENDAR_DAYS_INCLUSIVE' });
const defaultMetadata = (type) => (type === 'LEAVE_TYPE' ? defaultLeaveRules() : type === 'CONTRACT_TYPE' ? { internship: false } : {});
const payload = (type) => ({ type, code: '', label: '', active: true, position: 0, metadata: defaultMetadata(type) });

const createForm = useForm(payload(selectedType.value));
const editForm = useForm(payload(selectedType.value));
const editingUuid = ref(null);
const createKey = ref(0);

const chooseType = (type) => {
    selectedType.value = type;
    editingUuid.value = null;
    createForm.clearErrors();
    Object.assign(createForm, payload(type));
    createKey.value += 1;
};
const submitCreate = () => createForm
    .post(hrUrl('/administration/settings'), {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(createForm, payload(selectedType.value));
            createKey.value += 1;
        },
    });
const startEdit = (item) => {
    editingUuid.value = item.uuid;
    editForm.clearErrors();
    Object.assign(editForm, {
        type: item.type,
        code: item.code,
        label: item.label,
        active: item.active,
        position: item.position,
        metadata: item.type === 'LEAVE_TYPE'
            ? { ...defaultLeaveRules(), ...(item.metadata ?? {}) }
            : item.type === 'CONTRACT_TYPE' ? { internship: Boolean(item.internship) } : {},
    });
};
const submitEdit = (item) => editForm
    .put(hrUrl(`/administration/settings/${item.uuid}`), { preserveScroll: true, onSuccess: () => { editingUuid.value = null; } });

// --- Archiver : un motif, dans une fenêtre qui le demande -------------------
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (item) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = item;
};
const archive = () => {
    if (!archiveForm.reason.trim()) return;
    archiveForm.delete(hrUrl(`/administration/settings/${archiving.value.uuid}`), { preserveScroll: true, onSuccess: () => { archiving.value = null; } });
};
const restore = (item) => router.post(hrUrl(`/administration/settings/${item.uuid}/restore`), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Paramètres RH" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Référentiels et règles configurables"
            title="Paramètres RH"
            description="Contrats, congés, attestations et filières de stage. Les règles sont appliquées par le serveur, jamais seulement par l’écran."
            :icon="Settings"
            tone="slate"
        />

        <!-- ADR-188 — départements et fonctions ont chacun leur module. -->
        <nav class="grid gap-3 sm:grid-cols-2" aria-label="Structure RH">
            <Link
                v-for="module in STRUCTURE_MODULES"
                :key="module.href"
                :href="module.href"
                class="group flex items-center gap-3 rounded-xl border border-border bg-card p-3.5 shadow-sm transition-colors hover:border-primary/40 hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="module.icon" class="h-5 w-5" /></span>
                <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-foreground">{{ module.label }}</span><span class="block text-xs text-muted-foreground">{{ module.hint }} · se gèrent dans leur module</span></span>
                <ArrowRight class="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </Link>
        </nav>

        <div class="grid gap-5 xl:grid-cols-[260px_minmax(0,1fr)]">
            <nav class="h-fit overflow-x-auto rounded-xl border border-border bg-card p-2 shadow-sm xl:sticky xl:top-4" aria-label="Référentiels">
                <div class="flex min-w-max gap-1 xl:min-w-0 xl:flex-col">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        :aria-current="selectedType === type.value ? 'page' : undefined"
                        :class="cn('flex min-w-44 items-center gap-2.5 rounded-lg px-3 py-2.5 text-start text-sm font-semibold transition xl:w-full xl:min-w-0',
                            selectedType === type.value ? 'bg-primary text-primary-foreground shadow-sm' : 'text-foreground hover:bg-accent')"
                        @click="chooseType(type.value)"
                    >
                        <component :is="TYPES[type.value]?.icon ?? Settings" class="h-4 w-4 shrink-0" />
                        <span class="flex-1 truncate">{{ type.label }}</span>
                        <span :class="cn('rounded-full px-2 py-0.5 text-[11px] tabular-nums', selectedType === type.value ? 'bg-white/20' : 'bg-muted text-muted-foreground')">{{ references?.[type.value]?.length ?? 0 }}</span>
                    </button>
                </div>
            </nav>

            <main class="min-w-0 space-y-4">
                <section class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><component :is="meta.icon" class="h-5 w-5" /></span>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-primary">{{ typeLabel }}</p>
                            <h2 class="mt-0.5 text-base font-bold text-foreground">{{ meta.title }}</h2>
                            <p class="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground">{{ meta.description }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Badge variant="success">{{ activeCount }} actif{{ activeCount > 1 ? 's' : '' }}</Badge>
                        <Badge variant="outline">{{ archivedCount }} archivé{{ archivedCount > 1 ? 's' : '' }}</Badge>
                    </div>
                </section>

                <section v-if="can('hr_settings.create')" class="rounded-xl border border-border bg-card p-5 shadow-sm">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Plus class="h-4 w-4" /></span>
                        <div><h3 class="text-sm font-semibold text-foreground">Ajouter une valeur</h3><p class="mt-0.5 text-xs text-muted-foreground">Le code reste stable dans les données ; le libellé est ce que lisent les utilisateurs.</p></div>
                    </div>
                    <ReferenceValueForm
                        :key="createKey"
                        :form="createForm"
                        :type="selectedType"
                        :leave-day-count-methods="leaveDayCountMethods"
                        @submit="submitCreate"
                    />
                </section>

                <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <header class="border-b border-border px-5 py-3"><p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Valeurs du référentiel</p></header>
                    <div v-if="items.length" class="divide-y divide-border">
                        <article v-for="item in items" :key="item.uuid" class="p-4 sm:p-5">
                            <ReferenceValueForm
                                v-if="editingUuid === item.uuid"
                                :form="editForm"
                                :type="item.type"
                                mode="edit"
                                :leave-day-count-methods="leaveDayCountMethods"
                                @submit="submitEdit(item)"
                                @cancel="editingUuid = null"
                            />
                            <div v-else class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-md bg-muted px-2 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground">{{ item.code }}</span>
                                        <h4 :class="cn('text-sm font-semibold', item.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ item.label }}</h4>
                                        <Badge v-if="item.archived" variant="outline">Archivé</Badge>
                                        <Badge v-if="item.internship" variant="secondary"><GraduationCap class="h-3.5 w-3.5" />Contrat de stage</Badge>
                                    </div>

                                    <p v-if="item.delete_reason" class="text-xs text-muted-foreground">Motif : {{ item.delete_reason }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <span class="me-1 text-xs text-muted-foreground">Ordre {{ item.position }}</span>
                                    <Button v-if="!item.archived && can('hr_settings.update')" size="icon-xs" variant="outline" title="Modifier" aria-label="Modifier" @click="startEdit(item)"><Pencil class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="!item.archived && can('hr_settings.archive')" size="icon-xs" variant="outline" title="Archiver" aria-label="Archiver" @click="openArchive(item)"><Archive class="h-3.5 w-3.5" /></Button>
                                    <Button v-if="item.archived && can('hr_settings.restore')" size="xs" variant="outline" @click="restore(item)"><ArchiveRestore class="h-3.5 w-3.5" />Restaurer</Button>
                                </div>
                            </div>
                        </article>
                    </div>
                    <EmptyState v-else :icon="FileText" title="Aucune valeur configurée" description="Ajoutez la première valeur de ce référentiel pour la rendre disponible dans les formulaires." />
                </section>

            </main>
        </div>
    </div>

    <Dialog :open="Boolean(archiving)" title="Archiver cette valeur" :description="archiving ? `« ${archiving.label} » ne sera plus proposée. Les dossiers qui l’utilisent la gardent.` : ''" @update:open="(open) => { if (!open) archiving = null; }">
        <FormField label="Motif" required :error="archiveForm.errors.reason">
            <Textarea v-model="archiveForm.reason" rows="3" maxlength="1000" placeholder="Pourquoi archiver cette valeur ?" />
        </FormField>
        <template #footer>
            <Button type="button" variant="outline" @click="archiving = null">Annuler</Button>
            <Button type="button" variant="destructive" :disabled="archiveForm.processing || !archiveForm.reason.trim()" @click="archive"><Archive class="h-4 w-4" />Archiver</Button>
        </template>
    </Dialog>
</template>
