<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    BadgeCheck,
    Ban,
    Banknote,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Gift,
    Handshake,
    Hourglass,
    Pencil,
    Plus,
    RotateCcw,
    Stethoscope,
    UserRound,
    Users,
    Wallet,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import AdvantageArticleDialog from '@/Components/Bonus/AdvantageArticleDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';
import { monthLabel, shiftMonth } from '@/utilities/bonus';

/**
 * Avantages à l'acte (ADR-226) : chaque article (ECHO, ECG, CHIR…) rapporte son
 * prix unitaire par acte compté dans le mois — acte réalisé par la personne, ou
 * fait sur le passage d'un patient qu'elle a référé. RIVO compte ; le RH valide
 * (quantités et prix figés) puis marque versé. Le versement se fait hors RIVO.
 */
const props = defineProps({
    month: { type: String, required: true },
    currentMonth: { type: String, required: true },
    board: { type: Object, required: true },
    sources: { type: Array, default: () => [] },
    /** `null` sans `bonus_categories.view` : la gestion des articles n'existe pas. */
    articles: { type: Array, default: null },
    catalog: { type: Array, default: null },
});

const { can } = usePermissions();

const goTo = (month) => router.get(hrUrl('/administration/bonus'), { mois: month, onglet: 'avantages' }, { preserveScroll: true, preserveState: true });

const summaryCards = computed(() => [
    { key: 'to_validate', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', value: props.board.summary.to_validate, label: 'À valider', hint: 'Actes comptés, avantage pas encore validé' },
    { key: 'validated', icon: Wallet, tone: 'bg-primary/10 text-primary', value: props.board.summary.validated, label: 'Validés, à verser', hint: formatMoney(props.board.summary.amount_to_pay) },
    { key: 'paid', icon: Banknote, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300', value: props.board.summary.paid, label: 'Versés', hint: formatMoney(props.board.summary.amount_paid) },
]);

const activeArticles = computed(() => (props.articles ?? []).filter((article) => ! article.archived));
const archivedArticles = computed(() => (props.articles ?? []).filter((article) => article.archived));
const sourceIcon = (source) => (source === 'REFERRED' ? Users : Stethoscope);

/** Une ligne à afficher : figée sur l'avantage s'il est validé, comptée sinon. */
const rowLines = (person) => (person.award?.lines?.length ? person.award.lines : person.lines);
const rowTotal = (person) => (person.award ? person.award.total_amount : person.total);

// — Gestes sur un avantage : valider, marquer versé, annuler. —
const pending = ref(null);
const awardForm = useForm({ note: '', reason: '' });
const openAward = (mode, person) => {
    awardForm.reset();
    awardForm.clearErrors();
    pending.value = { mode, person };
};
const awardCopy = computed(() => {
    const mode = pending.value?.mode;
    if (mode === 'pay') return { title: 'Marquer l’avantage versé', confirm: 'Marquer versé', tone: 'success', icon: Banknote };
    if (mode === 'cancel') return { title: 'Annuler l’avantage', confirm: 'Annuler l’avantage', tone: 'danger', icon: Ban };

    return { title: 'Valider l’avantage', confirm: 'Valider l’avantage', tone: 'primary', icon: BadgeCheck };
});
const confirmAward = () => {
    const { mode, person } = pending.value;
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { pending.value = null; } };

    if (mode === 'validate') {
        awardForm.transform(() => ({ type: person.type, uuid: person.uuid, mois: props.month })).post(hrUrl('/administration/bonus/avantages/awards'), options);
    } else if (mode === 'pay') {
        awardForm.transform((data) => ({ note: data.note })).post(hrUrl(`/administration/bonus/avantages/awards/${person.award.uuid}/pay`), options);
    } else {
        awardForm.transform((data) => ({ reason: data.reason })).post(hrUrl(`/administration/bonus/avantages/awards/${person.award.uuid}/cancel`), options);
    }
};
const awardError = computed(() => Object.values(awardForm.errors)[0] ?? '');

// — Articles. —
const articleOpen = ref(false);
const editing = ref(null);
const openArticle = (article = null) => {
    editing.value = article;
    articleOpen.value = true;
};
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (article) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = article;
};
const confirmArchive = () => archiveForm.delete(hrUrl(`/administration/bonus/avantages/articles/${archiving.value.uuid}`), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { archiving.value = null; },
});
const restore = (article) => router.post(hrUrl(`/administration/bonus/avantages/articles/${article.uuid}/restore`), {}, { preserveScroll: true, preserveState: true });

defineExpose({ openArticle });
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1 shadow-sm">
                <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="goTo(shiftMonth(month, -1))"><ChevronLeft class="h-4 w-4" /></Button>
                <span class="flex min-w-44 items-center justify-center gap-2 px-2 text-sm font-semibold capitalize text-foreground">
                    <CalendarDays class="h-4 w-4 text-muted-foreground" />{{ monthLabel(month) }}
                </span>
                <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="goTo(shiftMonth(month, 1))"><ChevronRight class="h-4 w-4" /></Button>
            </div>
            <Button v-if="articles && can('bonus_categories.create')" type="button" variant="outline" @click="openArticle()"><Plus class="h-4 w-4" />Nouvel article</Button>
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

        <Card v-if="articles && ! activeArticles.length" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Gift class="h-6 w-6" /></span>
            <p class="text-sm font-semibold text-foreground">Aucun article d’avantage</p>
            <p class="max-w-md text-sm text-muted-foreground">Un article (ECHO, ECG, CHIR…) regroupe des actes du catalogue et fixe un prix unitaire. Chaque acte compté dans le mois rapporte ce prix à la personne.</p>
            <Button v-if="can('bonus_categories.create')" type="button" @click="openArticle()"><Plus class="h-4 w-4" />Créer le premier article</Button>
        </Card>

        <p v-else-if="! board.people.length" class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            Aucun acte compté ce mois-ci. Seules comptent les personnes dont les avantages sont ouverts (case « Avantages » du dossier ou fonction), et les partenaires en service.
        </p>

        <Card v-for="person in board.people" :key="person.key" class="overflow-hidden">
            <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border px-4 py-3">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', person.type === 'PARTNER' ? 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300' : 'bg-primary/10 text-primary')">
                    <component :is="person.type === 'PARTNER' ? Handshake : UserRound" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-foreground">{{ person.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ [person.reference, person.detail].filter(Boolean).join(' · ') }}</p>
                </div>
                <span class="rounded-lg bg-primary/5 px-3 py-1.5 text-sm font-bold tabular-nums text-primary">{{ formatMoney(rowTotal(person)) }}</span>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Article</th>
                            <th scope="col" class="px-4 py-2 text-start font-medium">Compte</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Qté</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">PU</th>
                            <th scope="col" class="px-4 py-2 text-end font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="line in rowLines(person)" :key="line.article_uuid ?? line.article">
                            <td class="px-4 py-2 align-top">
                                <span class="font-semibold text-foreground">{{ line.article }}</span>
                                <details v-if="line.references?.length" class="mt-0.5 text-xs">
                                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Passages comptés</summary>
                                    <p class="mt-1 flex flex-wrap gap-1">
                                        <span v-for="number in line.references" :key="number" class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">{{ number }}</span>
                                    </p>
                                </details>
                            </td>
                            <td class="px-4 py-2 align-top text-xs text-muted-foreground">
                                <span class="inline-flex items-center gap-1"><component :is="sourceIcon(line.source)" class="h-3.5 w-3.5" />{{ line.source_label }}</span>
                            </td>
                            <td class="px-4 py-2 text-end align-top tabular-nums text-foreground">{{ line.quantity }}</td>
                            <td class="px-4 py-2 text-end align-top tabular-nums text-muted-foreground">{{ formatMoney(line.unit_price) }}</td>
                            <td class="px-4 py-2 text-end align-top font-semibold tabular-nums text-foreground">{{ formatMoney(line.total) }}</td>
                        </tr>
                        <tr v-if="! rowLines(person).length">
                            <td colspan="5" class="px-4 py-3 text-center text-xs text-muted-foreground">Rien compté ce mois-ci.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3">
                <template v-if="person.award?.status === 'PAID'">
                    <Badge variant="success"><Banknote class="h-3.5 w-3.5" />Versé</Badge>
                    <span class="text-xs text-muted-foreground">{{ formatDateTime(person.award.paid_at) }} · {{ person.award.paid_by }}<template v-if="person.award.payment_note"> — {{ person.award.payment_note }}</template></span>
                </template>
                <template v-else-if="person.award?.status === 'VALIDATED'">
                    <Badge variant="warning"><Wallet class="h-3.5 w-3.5" />Validé · à verser</Badge>
                    <span class="text-xs text-muted-foreground">le {{ formatDateTime(person.award.validated_at) }} · {{ person.award.validated_by }}</span>
                    <span class="ms-auto flex gap-2">
                        <Button v-if="can('bonus_awards.cancel')" type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" @click="openAward('cancel', person)"><Ban class="h-4 w-4" />Annuler</Button>
                        <Button v-if="can('bonus_awards.pay')" type="button" size="sm" variant="success" @click="openAward('pay', person)"><Banknote class="h-4 w-4" />Marquer versé</Button>
                    </span>
                </template>
                <template v-else-if="Number(person.total) > 0">
                    <Badge variant="outline"><Hourglass class="h-3.5 w-3.5" />À valider</Badge>
                    <span class="text-xs text-muted-foreground">Le serveur recompte au moment de valider.</span>
                    <Button v-if="can('bonus_awards.validate') && month <= currentMonth" type="button" size="sm" class="ms-auto" @click="openAward('validate', person)"><BadgeCheck class="h-4 w-4" />Valider l’avantage</Button>
                </template>

                <details v-if="person.cancelled.length" class="w-full text-xs">
                    <summary class="cursor-pointer text-muted-foreground hover:text-foreground">{{ person.cancelled.length }} avantage{{ person.cancelled.length > 1 ? 's' : '' }} annulé{{ person.cancelled.length > 1 ? 's' : '' }}</summary>
                    <p v-for="award in person.cancelled" :key="award.uuid" class="mt-1 text-muted-foreground">{{ formatMoney(award.total_amount) }} · {{ formatDateTime(award.cancelled_at) }} · {{ award.cancelled_by }} — {{ award.cancel_reason }}</p>
                </details>
            </footer>
        </Card>

        <section v-if="articles && articles.length" class="space-y-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground"><Gift class="h-4 w-4 text-muted-foreground" />Articles · {{ activeArticles.length }}</h2>
            <Card class="overflow-hidden">
                <ul class="divide-y divide-border">
                    <li v-for="article in [...activeArticles, ...archivedArticles]" :key="article.uuid" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                        <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', article.archived ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary')">
                            <component :is="sourceIcon(article.source)" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1 basis-60">
                            <p class="flex flex-wrap items-center gap-2">
                                <span :class="cn('text-sm font-semibold', article.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ article.name }}</span>
                                <Badge variant="outline">{{ article.source_label }}</Badge>
                                <Badge v-if="article.archived" variant="outline">Archivé</Badge>
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <strong class="font-semibold text-foreground">{{ formatMoney(article.unit_price) }}</strong> par acte ·
                                {{ article.items.map((item) => item.name).join(', ') }}
                            </p>
                            <p v-if="article.archived && article.delete_reason" class="mt-0.5 text-xs text-muted-foreground">Archivé : {{ article.delete_reason }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <template v-if="! article.archived">
                                <Button v-if="can('bonus_categories.update')" type="button" variant="ghost" size="icon" :aria-label="`Modifier ${article.name}`" @click="openArticle(article)"><Pencil class="h-4 w-4" /></Button>
                                <Button v-if="can('bonus_categories.archive')" type="button" variant="ghost" size="icon" :aria-label="`Archiver ${article.name}`" @click="openArchive(article)"><Archive class="h-4 w-4" /></Button>
                            </template>
                            <Button v-else-if="can('bonus_categories.restore')" type="button" variant="outline" size="sm" @click="restore(article)"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                        </div>
                    </li>
                </ul>
            </Card>
        </section>

        <AdvantageArticleDialog v-if="articles" v-model:open="articleOpen" :article="editing" :sources="sources" :catalog="catalog ?? []" />

        <ConfirmModal
            :open="pending !== null"
            :title="awardCopy.title"
            :description="pending ? `${pending.person.name} · ${monthLabel(month)}` : ''"
            :confirm-label="awardCopy.confirm"
            :tone="awardCopy.tone"
            :icon="awardCopy.icon"
            :processing="awardForm.processing"
            :disabled="pending?.mode === 'cancel' && ! awardForm.reason.trim()"
            :dismissible="false"
            @update:open="(open) => open || awardForm.processing || (pending = null)"
            @confirm="confirmAward"
        >
            <div v-if="pending" class="space-y-4 text-sm">
                <template v-if="pending.mode === 'validate'">
                    <p class="text-foreground">
                        Avantage de <strong>{{ formatMoney(pending.person.total) }}</strong> pour {{ pending.person.lines.length }} article{{ pending.person.lines.length > 1 ? 's' : '' }}.
                    </p>
                    <p class="text-muted-foreground">Le serveur recompte au moment de valider. Quantités et prix unitaires sont figés sur l’avantage ; changer un prix ensuite ne le réécrit pas. Le versement se fait hors RIVO, puis se marque ici.</p>
                </template>
                <template v-else-if="pending.mode === 'pay'">
                    <p class="text-foreground">L’avantage de <strong>{{ formatMoney(pending.person.award.total_amount) }}</strong> a été remis hors RIVO.</p>
                    <FormField label="Note" hint="(facultatif)">
                        <Textarea v-model="awardForm.note" :rows="2" maxlength="500" placeholder="Ex. versé avec la paie de septembre" />
                    </FormField>
                </template>
                <template v-else>
                    <p class="text-muted-foreground">L’avantage reste dans l’historique, marqué annulé ; il pourra être validé de nouveau.</p>
                    <FormField label="Motif" required>
                        <Textarea v-model="awardForm.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cet avantage est annulé" />
                    </FormField>
                </template>
                <p v-if="awardError" class="text-sm font-medium text-destructive">{{ awardError }}</p>
            </div>
        </ConfirmModal>

        <ConfirmModal
            :open="archiving !== null"
            :title="archiving ? `Archiver « ${archiving.name} »` : ''"
            description="Il ne compte plus rien à partir d’aujourd’hui. Les avantages déjà validés restent, et il se restaure à tout moment."
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
                <Textarea v-model="archiveForm.reason" :rows="2" maxlength="1000" placeholder="Pourquoi cet article s’arrête" />
            </FormField>
        </ConfirmModal>
    </div>
</template>
