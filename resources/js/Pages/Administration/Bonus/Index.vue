<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    BadgeCheck,
    Ban,
    Banknote,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Hourglass,
    Layers,
    Medal,
    Gift,
    HandCoins,
    Pencil,
    Plus,
    RotateCcw,
    Trophy,
    Users,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AdvantagesPanel from '@/Components/Bonus/AdvantagesPanel.vue';
import AdvantageEntriesDialog from '@/Components/Bonus/AdvantageEntriesDialog.vue';
import AdvantageEntriesPanel from '@/Components/Bonus/AdvantageEntriesPanel.vue';
import BonusCategoryDialog from '@/Components/Bonus/BonusCategoryDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { bonusRowState, monthLabel, shiftMonth } from '@/utilities/bonus';

/**
 * ADR-212 — les bonus du personnel. Une catégorie compte chaque mois une
 * mesure (patients recommandés, consultations, interventions…) ; qui atteint
 * son seuil reçoit son montant, une fois validé par les RH. Le versement se
 * fait hors RIVO : on le trace, on ne le calcule pas (ADR-066, ADR-206).
 *
 * L'écran ne décide rien : valider recompte sur le serveur.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    board: { type: Object, required: true },
    measures: { type: Array, default: () => [] },
    /** `null` sans `bonus_categories.view` : l'onglet n'existe pas. */
    categories: { type: Array, default: null },
    staff: { type: Array, default: null },
    /** Onglet ouvert à l'arrivée : « bonus » ou « advantages » (`?onglet=avantages`). */
    tab: { type: String, default: 'bonus' },
    /** Avantages à l'acte (ADR-226). */
    advantages: { type: Object, required: true },
    advantageSources: { type: Array, default: () => [] },
    advantageArticles: { type: Array, default: null },
    catalogChoices: { type: Array, default: null },
    /** Avantages saisis (ADR-227) ; `null` sans `advantage_entries.view`. */
    entries: { type: Object, default: null },
});

const { can } = usePermissions();

const tab = ref(props.tab === 'entries' && props.entries ? 'entries' : props.tab === 'advantages' ? 'advantages' : (props.board.categories.length || ! props.categories ? 'month' : 'categories'));
const filter = ref('all');
const entriesOpen = ref(false);

const FILTERS = [
    { value: 'all', label: 'Tout le personnel' },
    { value: 'reached', label: 'Seuil atteint' },
    { value: 'to_pay', label: 'À verser' },
];

const isFuture = computed(() => props.month > props.currentMonth);
const goTo = (month) => router.get(hrUrl('/administration/bonus'), { mois: month }, { preserveScroll: true, preserveState: true });

const summaryCards = computed(() => [
    { key: 'to_validate', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: props.board.summary.to_validate, label: 'À valider', hint: 'Seuil atteint, bonus pas encore validé' },
    { key: 'validated', icon: Wallet, tone: 'bg-primary/10 text-primary', value: props.board.summary.validated, label: 'Validés, à verser', hint: formatMoney(props.board.summary.amount_to_pay) },
    { key: 'paid', icon: Banknote, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: props.board.summary.paid, label: 'Versés', hint: formatMoney(props.board.summary.amount_paid) },
]);

const visibleEmployees = (category) => category.employees.filter((employee) => {
    if (filter.value === 'reached') return employee.reached || employee.award;
    if (filter.value === 'to_pay') return employee.award?.status === 'VALIDATED';

    return true;
});
const shownCategories = computed(() => props.board.categories.filter((category) => filter.value === 'all' || visibleEmployees(category).length));

const progress = (employee, category) => Math.min(100, Math.round((employee.count / Math.max(1, category.threshold)) * 100));
const needsAccount = (category) => category.measure !== 'REFERRED_PATIENTS';

// — Gestes sur un bonus : valider, marquer versé, annuler. —
const pending = ref(null);
const awardForm = useForm({ category_uuid: '', employee_uuid: '', mois: '', note: '', reason: '' });

const openAward = (mode, category, employee) => {
    awardForm.reset();
    awardForm.clearErrors();
    pending.value = { mode, category, employee };
};
const closeAward = (open) => {
    if (! open && ! awardForm.processing) pending.value = null;
};
const awardCopy = computed(() => {
    const mode = pending.value?.mode;
    if (mode === 'pay') return { title: 'Marquer le bonus versé', confirm: 'Marquer versé', tone: 'success', icon: Banknote };
    if (mode === 'cancel') return { title: 'Annuler le bonus', confirm: 'Annuler le bonus', tone: 'danger', icon: Ban };

    return { title: 'Valider le bonus', confirm: 'Valider le bonus', tone: 'primary', icon: BadgeCheck };
});
const confirmAward = () => {
    const { mode, category, employee } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; } };

    if (mode === 'validate') {
        awardForm.transform(() => ({ category_uuid: category.uuid, employee_uuid: employee.uuid, mois: props.month }))
            .post(hrUrl('/administration/bonus/awards'), options);
    } else if (mode === 'pay') {
        awardForm.transform((data) => ({ note: data.note })).post(hrUrl(`/administration/bonus/awards/${employee.award.uuid}/pay`), options);
    } else {
        awardForm.transform((data) => ({ reason: data.reason })).post(hrUrl(`/administration/bonus/awards/${employee.award.uuid}/cancel`), options);
    }
};
const awardError = computed(() => Object.values(awardForm.errors)[0] ?? '');

// — Catégories. —
const categoryOpen = ref(false);
const editing = ref(null);
const openCategory = (category = null) => {
    editing.value = category;
    categoryOpen.value = true;
};

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (category) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = category;
};
const confirmArchive = () => archiveForm.delete(hrUrl(`/administration/bonus/categories/${archiving.value.uuid}`), {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});
const restore = (category) => router.post(hrUrl(`/administration/bonus/categories/${category.uuid}/restore`), {}, { preserveScroll: true });

const activeCategories = computed(() => (props.categories ?? []).filter((category) => ! category.archived));
const archivedCategories = computed(() => (props.categories ?? []).filter((category) => category.archived));
</script>

<template>
    <Head title="Bonus du personnel" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines · Pilotage"
            title="Bonus et avantages du personnel"
            description="Bonus : un seuil de patients par mois. Avantages : un prix par acte réalisé ou par patient référé. RIVO compte, les RH valident ; le versement se fait hors RIVO."
            :icon="Medal"
        >
            <template #actions>
                <Button v-if="entries && tab !== 'entries' && can('advantage_entries.create')" type="button" variant="outline" @click="entriesOpen = true">
                    <HandCoins class="h-4 w-4" />Saisir des avantages
                </Button>
                <Button v-if="categories && ['month', 'categories'].includes(tab) && can('bonus_categories.create')" type="button" @click="openCategory()">
                    <Plus class="h-4 w-4" />Nouvelle catégorie
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="tab">
            <TabsList aria-label="Bonus et avantages">
                <TabsTrigger v-if="entries" value="entries"><HandCoins class="h-4 w-4" />Avantages des médecins · {{ entries.summary.count }}</TabsTrigger>
                <TabsTrigger value="month"><Trophy class="h-4 w-4" />Bonus du mois</TabsTrigger>
                <TabsTrigger value="advantages"><Gift class="h-4 w-4" />Avantages comptés à l’acte</TabsTrigger>
                <TabsTrigger v-if="categories" value="categories"><Layers class="h-4 w-4" />Catégories · {{ activeCategories.length }}</TabsTrigger>
            </TabsList>

            <TabsContent value="month" class="space-y-5">
                <!-- Le mois lu : on remonte le temps, jamais au-delà du mois en cours. -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm">
                        <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
                        <span class="flex min-w-44 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                            <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
                        </span>
                        <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
                    </div>
                    <div class="inline-flex rounded-lg bg-muted p-1" role="group" aria-label="Filtrer le personnel">
                        <button
                            v-for="option in FILTERS"
                            :key="option.value"
                            type="button"
                            :aria-pressed="filter === option.value"
                            :class="cn('rounded-md px-3 py-1 text-sm font-medium transition-colors', filter === option.value ? 'bg-background text-foreground shadow' : 'text-muted-foreground hover:text-foreground')"
                            @click="filter = option.value"
                        >{{ option.label }}</button>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-for="card in summaryCards" :key="card.key" class="flex items-center gap-3 rounded-xl border border-border bg-card p-3.5 shadow-sm">
                        <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                        <span class="min-w-0">
                            <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ card.value }}</span>
                            <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                            <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                        </span>
                    </div>
                </div>

                <p v-if="isFuture" class="text-sm text-muted-foreground">Ce mois n’a pas commencé.</p>

                <Card v-if="! board.categories.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Medal class="h-6 w-6" /></span>
                    <p class="text-sm font-semibold text-foreground">Aucune catégorie de bonus</p>
                    <p class="max-w-md text-sm text-muted-foreground">Une catégorie dit ce qui est compté (patients recommandés, consultations, interventions…), à partir de combien de patients dans le mois, pour quel montant, et qui elle concerne.</p>
                    <Button v-if="categories && can('bonus_categories.create')" type="button" @click="openCategory()"><Plus class="h-4 w-4" />Créer la première catégorie</Button>
                </Card>

                <p v-else-if="! shownCategories.length" class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">Personne ne correspond à ce filtre ce mois-ci.</p>

                <Card v-for="category in shownCategories" :key="category.uuid" class="overflow-hidden">
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Medal class="h-5 w-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-foreground">
                                {{ category.name }}
                                <Badge v-if="category.archived" variant="outline">Archivée</Badge>
                            </p>
                            <p class="text-xs text-muted-foreground" :title="category.measure_description">
                                {{ category.measure_label }} · à partir de <strong class="font-semibold text-foreground">{{ category.threshold }}</strong> patient{{ category.threshold > 1 ? 's' : '' }} dans le mois
                            </p>
                        </div>
                        <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-sm font-bold tabular-nums text-primary">{{ formatMoney(category.amount) }}</span>
                    </header>

                    <p v-if="! category.employees.length" class="px-4 py-6 text-center text-sm text-muted-foreground">
                        Personne n’est encore concerné par cette catégorie.
                    </p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="employee in visibleEmployees(category)" :key="employee.uuid" class="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,14rem)_minmax(0,19rem)] md:items-center">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                    {{ employee.name }}
                                    <Badge v-if="! employee.in_post" variant="outline">Plus en poste</Badge>
                                    <Badge v-if="! employee.in_category" variant="outline">Retiré de la catégorie</Badge>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">{{ [employee.employee_number, employee.job_title].filter(Boolean).join(' · ') }}</p>
                                <p v-if="needsAccount(category) && ! employee.has_account" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                                    <CircleAlert class="h-3.5 w-3.5" />Aucun compte de connexion relié : ses patients ne sont pas comptés.
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="flex items-baseline justify-between gap-2 text-xs">
                                    <span class="font-semibold tabular-nums text-foreground">{{ employee.count }} / {{ category.threshold }}</span>
                                    <span class="text-muted-foreground">patient{{ employee.count > 1 ? 's' : '' }}</span>
                                </p>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="employee.count" :aria-valuemax="category.threshold" :aria-label="`${employee.count} patients sur ${category.threshold}`">
                                    <div :class="cn('h-full rounded-full transition-all', employee.reached ? 'bg-emerald-500' : 'bg-primary/60')" :style="{ width: `${progress(employee, category)}%` }" />
                                </div>
                                <details v-if="employee.count || employee.award" class="mt-1 text-xs">
                                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Patients comptés</summary>
                                    <p class="mt-1 flex flex-wrap gap-1">
                                        <span v-for="number in (employee.award?.counted_patients?.length ? employee.award.counted_patients : employee.patients)" :key="number" class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">{{ number }}</span>
                                    </p>
                                </details>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                                <template v-if="bonusRowState(employee) === 'PAID'">
                                    <Badge variant="success"><Banknote class="h-3.5 w-3.5" />Versé · {{ formatMoney(employee.award.amount) }}</Badge>
                                    <span class="w-full text-xs text-muted-foreground md:text-end">{{ formatDateTime(employee.award.paid_at) }} · {{ employee.award.paid_by }}<template v-if="employee.award.payment_note"> — {{ employee.award.payment_note }}</template></span>
                                </template>
                                <template v-else-if="bonusRowState(employee) === 'VALIDATED'">
                                    <Badge variant="warning"><Wallet class="h-3.5 w-3.5" />Validé · à verser {{ formatMoney(employee.award.amount) }}</Badge>
                                    <Button v-if="can('bonus_awards.pay')" type="button" size="sm" variant="success" @click="openAward('pay', category, employee)"><Banknote class="h-4 w-4" />Marquer versé</Button>
                                    <Button v-if="can('bonus_awards.cancel')" type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="openAward('cancel', category, employee)"><Ban class="h-4 w-4" />Annuler</Button>
                                    <span class="w-full text-xs text-muted-foreground md:text-end">Validé le {{ formatDateTime(employee.award.validated_at) }} · {{ employee.award.validated_by }}</span>
                                </template>
                                <template v-else-if="bonusRowState(employee) === 'TO_VALIDATE'">
                                    <Badge variant="success"><Trophy class="h-3.5 w-3.5" />Seuil atteint</Badge>
                                    <Button v-if="can('bonus_awards.validate') && employee.in_category && ! category.archived" type="button" size="sm" @click="openAward('validate', category, employee)"><BadgeCheck class="h-4 w-4" />Valider le bonus</Button>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">Encore {{ category.threshold - employee.count }} patient{{ category.threshold - employee.count > 1 ? 's' : '' }}</span>

                                <details v-if="employee.cancelled.length" class="w-full text-xs md:text-end">
                                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">{{ employee.cancelled.length }} bonus annulé{{ employee.cancelled.length > 1 ? 's' : '' }}</summary>
                                    <p v-for="award in employee.cancelled" :key="award.uuid" class="mt-1 text-muted-foreground">{{ formatDateTime(award.cancelled_at) }} · {{ award.cancelled_by }} — {{ award.cancel_reason }}</p>
                                </details>
                            </div>
                        </li>
                    </ul>
                </Card>
            </TabsContent>

            <TabsContent value="advantages">
                <AdvantagesPanel
                    :month="month"
                    :current-month="currentMonth"
                    :board="advantages"
                    :sources="advantageSources"
                    :articles="advantageArticles"
                    :catalog="catalogChoices"
                />
            </TabsContent>

            <TabsContent v-if="entries" value="entries">
                <AdvantageEntriesPanel :month="month" :current-month="currentMonth" :entries="entries" @add="entriesOpen = true" />
            </TabsContent>

            <TabsContent v-if="categories" value="categories" class="space-y-4">
                <Card v-if="! categories.length" class="px-6 py-10 text-center text-sm text-muted-foreground">Aucune catégorie pour l’instant.</Card>
                <Card v-else class="overflow-hidden">
                    <ul class="divide-y divide-border">
                        <li v-for="category in [...activeCategories, ...archivedCategories]" :key="category.uuid" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', category.archived ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary')"><Medal class="h-5 w-5" /></span>
                            <div class="min-w-0 flex-1 basis-60">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span :class="cn('text-sm font-semibold', category.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ category.name }}</span>
                                    <Badge v-if="category.archived" variant="outline">Archivée</Badge>
                                </p>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                    <span>{{ category.measure_label }}</span>
                                    <span>Seuil {{ category.threshold }} · {{ formatMoney(category.amount) }}</span>
                                    <span class="inline-flex items-center gap-1"><Users class="h-3 w-3" />{{ category.employees.filter((employee) => employee.in_post).length }} concerné(s)</span>
                                    <span v-if="category.awards_count">{{ category.awards_count }} bonus donné(s)</span>
                                </p>
                                <p v-if="category.archived && category.delete_reason" class="mt-0.5 text-xs text-muted-foreground">Archivée : {{ category.delete_reason }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <template v-if="! category.archived">
                                    <Button v-if="can('bonus_categories.update')" type="button" variant="ghost" size="icon" :aria-label="`Modifier ${category.name}`" @click="openCategory(category)"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="can('bonus_categories.archive')" type="button" variant="ghost" size="icon" :aria-label="`Archiver ${category.name}`" @click="openArchive(category)"><Archive class="h-4 w-4" /></Button>
                                </template>
                                <Button v-else-if="can('bonus_categories.restore')" type="button" variant="outline" size="sm" @click="restore(category)"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                            </div>
                        </li>
                    </ul>
                </Card>
            </TabsContent>
        </Tabs>

        <AdvantageEntriesDialog
            v-if="entries"
            v-model:open="entriesOpen"
            :month="month"
            :current-month="currentMonth"
            :doctors="entries.doctors"
            :articles="entries.articles ?? []"
        />
        <BonusCategoryDialog v-if="categories" v-model:open="categoryOpen" :category="editing" :measures="measures" :staff="staff ?? []" />

        <ConfirmModal
            :open="pending !== null"
            :title="awardCopy.title"
            :description="pending ? `${pending.employee.name} · ${pending.category.name} · ${monthLabel(month)}` : ''"
            :confirm-label="awardCopy.confirm"
            :tone="awardCopy.tone"
            :icon="awardCopy.icon"
            :processing="awardForm.processing"
            :disabled="pending?.mode === 'cancel' && ! awardForm.reason.trim()"
            :dismissible="false"
            @update:open="closeAward"
            @confirm="confirmAward"
        >
            <div v-if="pending" class="space-y-4 text-sm">
                <template v-if="pending.mode === 'validate'">
                    <p class="text-foreground">
                        <strong>{{ pending.employee.count }}</strong> patient{{ pending.employee.count > 1 ? 's' : '' }} ce mois-ci pour un seuil de <strong>{{ pending.category.threshold }}</strong> :
                        bonus de <strong>{{ formatMoney(pending.category.amount) }}</strong>.
                    </p>
                    <p class="text-muted-foreground">Le serveur recompte au moment de valider. Le montant et les patients comptés sont figés sur le bonus ; le versement se fait hors RIVO, puis se marque ici.</p>
                </template>
                <template v-else-if="pending.mode === 'pay'">
                    <p class="text-foreground">Le bonus de <strong>{{ formatMoney(pending.employee.award.amount) }}</strong> a été remis hors RIVO.</p>
                    <FormField label="Note" hint="(facultatif)">
                        <Textarea v-model="awardForm.note" :rows="2" maxlength="500" placeholder="Ex. versé avec la paie de septembre" />
                    </FormField>
                </template>
                <template v-else>
                    <p class="text-muted-foreground">Le bonus reste dans l’historique, marqué annulé ; il pourra être validé de nouveau si le seuil est toujours atteint.</p>
                    <FormField label="Motif" required>
                        <Textarea v-model="awardForm.reason" :rows="2" maxlength="1000" placeholder="Pourquoi ce bonus est annulé" />
                    </FormField>
                </template>
                <p v-if="awardError" class="text-sm font-medium text-destructive">{{ awardError }}</p>
            </div>
        </ConfirmModal>

        <ConfirmModal
            :open="archiving !== null"
            :title="archiving ? `Archiver « ${archiving.name} »` : ''"
            description="Elle ne compte plus rien à partir d’aujourd’hui. Les bonus déjà donnés restent, et elle se restaure à tout moment."
            confirm-label="Archiver"
            tone="danger"
            :icon="Archive"
            :processing="archiveForm.processing"
            :disabled="! archiveForm.reason.trim()"
            :dismissible="false"
            @update:open="(open) => open || archiveForm.processing || (archiving = null)"
            @confirm="confirmArchive"
        >
            <FormField label="Motif" required :error="archiveForm.errors.reason">
                <Textarea v-model="archiveForm.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cette catégorie s’arrête" />
            </FormField>
        </ConfirmModal>
    </div>
</template>
