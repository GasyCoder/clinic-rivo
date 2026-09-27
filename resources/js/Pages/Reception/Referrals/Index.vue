<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Check, ChevronLeft, ChevronRight, Gift, Handshake, PackageCheck, PackageOpen, Search, UserRound, Users, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '@/Pages/Administration/Partials/HrPagination.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatDateTime, monthLabel, shiftMonth } from '@/utilities/date';

/**
 * ADR-212 — qui a recommandé la clinique à chaque nouveau patient, et le
 * cadeau qui lui a été remis. La recommandation se note à l'accueil du
 * patient ; ici, on la relit et on marque le cadeau remis — une seule fois.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    referrals: { type: Object, required: true },
    counts: { type: Object, required: true },
    filters: { type: Object, required: true },
    currentMonth: { type: String, required: true },
});

const { can } = usePermissions();

const CARDS = [
    { value: 'tous', label: 'Toutes', hint: 'Recommandations notées', icon: Users, tone: 'bg-primary/10 text-primary' },
    { value: 'a-remettre', label: 'Cadeau à remettre', hint: 'Pas encore remis', icon: PackageOpen, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
    { value: 'remis', label: 'Cadeau remis', hint: 'Remis et tracé', icon: PackageCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
];
const SOURCE_ICONS = { EMPLOYEE: Users, PARTNER: Handshake, OTHER: UserRound };

const query = ref(props.filters.q ?? '');
const month = ref(props.filters.mois ?? '');

const reload = (changes) => router.get('/reception/recommandations', {
    q: query.value || undefined,
    mois: month.value || undefined,
    cadeau: props.filters.cadeau !== 'tous' ? props.filters.cadeau : undefined,
    ...changes,
}, { preserveScroll: true, preserveState: true, replace: true });

let timer = null;
watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({}), 350);
});
watch(month, () => reload({}));

const rows = computed(() => props.referrals.data ?? []);

const giving = ref(null);
const giftForm = useForm({ note: '' });
const openGift = (referral) => {
    giftForm.reset();
    giftForm.clearErrors();
    giving.value = referral;
};
const confirmGift = () => giftForm.post(`/reception/recommandations/${giving.value.uuid}/cadeau`, {
    preserveScroll: true,
    onSuccess: () => { giving.value = null; },
});
const giftError = computed(() => Object.values(giftForm.errors)[0] ?? '');
</script>

<template>
    <Head title="Recommandations" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Réception"
            title="Recommandations"
            description="Qui a recommandé la clinique à chaque nouveau patient. La personne notée reçoit un cadeau à la clinique : on le marque remis ici."
            :icon="Gift"
        />

        <div class="grid gap-3 sm:grid-cols-3" role="group" aria-label="Filtrer par cadeau">
            <button
                v-for="card in CARDS"
                :key="card.value"
                type="button"
                :aria-pressed="filters.cadeau === card.value"
                :class="cn(
                    'relative flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filters.cadeau === card.value ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                )"
                @click="reload({ cadeau: card.value === 'tous' ? undefined : card.value, page: undefined })"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ counts[card.value] }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
                <Check v-if="filters.cadeau === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:w-80">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient, n° ou qui a recommandé" aria-label="Rechercher une recommandation" class="pe-9" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <!-- Un mois se parcourt comme sur la page des bonus : jamais au-delà du mois en cours. -->
                <div v-if="month" class="flex items-center gap-1" role="group" aria-label="Mois">
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois précédent" @click="month = shiftMonth(month, -1)"><ChevronLeft class="h-4 w-4" /></Button>
                    <span class="inline-flex min-w-36 items-center justify-center gap-2 text-sm font-medium capitalize text-foreground" aria-live="polite">
                        <CalendarDays class="h-4 w-4 text-muted-foreground" aria-hidden="true" />{{ monthLabel(month) }}
                    </span>
                    <Button type="button" variant="ghost" size="icon" aria-label="Mois suivant" :disabled="month >= currentMonth" @click="month = shiftMonth(month, 1)"><ChevronRight class="h-4 w-4" /></Button>
                    <Button type="button" variant="ghost" size="sm" @click="month = ''"><X class="h-4 w-4" />Tous les mois</Button>
                </div>
                <Button v-else type="button" variant="outline" size="sm" @click="month = currentMonth"><CalendarDays class="h-4 w-4" />Filtrer par mois</Button>
            </div>

            <div v-if="! rows.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><Gift class="h-6 w-6" /></span>
                <p class="text-sm font-semibold text-foreground">Aucune recommandation</p>
                <p class="max-w-md text-sm text-muted-foreground">Elle se note à l’accueil d’un nouveau patient : « Une personne a recommandé la clinique à ce patient ».</p>
            </div>

            <ul v-else class="divide-y divide-border">
                <li v-for="referral in rows" :key="referral.uuid" class="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,16rem)] md:items-center">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Patient</p>
                        <template v-if="referral.patient">
                            <Link v-if="referral.patient.uuid" :href="`/patients/${referral.patient.uuid}`" class="block truncate text-sm font-semibold text-foreground hover:text-primary hover:underline">{{ referral.patient.name }}</Link>
                            <p v-else class="truncate text-sm font-semibold text-foreground">{{ referral.patient.name }}</p>
                            <p class="font-mono text-xs text-muted-foreground">{{ referral.patient.patient_number }} · {{ formatDateTime(referral.referred_at) }}</p>
                        </template>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Recommandé par</p>
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                            <component :is="SOURCE_ICONS[referral.source]" class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            {{ referral.referrer_name }}
                            <Badge variant="secondary">{{ referral.source_label }}</Badge>
                        </p>
                        <p class="text-xs text-muted-foreground">{{ [referral.referrer_detail, referral.referrer_phone].filter(Boolean).join(' · ') || '—' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 md:justify-end">
                        <template v-if="referral.gift_given_at">
                            <Badge variant="success"><PackageCheck class="h-3.5 w-3.5" />Cadeau remis</Badge>
                            <span class="w-full text-xs text-muted-foreground md:text-end">{{ formatDateTime(referral.gift_given_at) }} · {{ referral.gift_given_by }}<template v-if="referral.gift_note"> — {{ referral.gift_note }}</template></span>
                        </template>
                        <template v-else>
                            <Badge variant="warning"><PackageOpen class="h-3.5 w-3.5" />À remettre</Badge>
                            <Button v-if="can('patient_referrals.gift')" type="button" size="sm" @click="openGift(referral)"><Gift class="h-4 w-4" />Remettre le cadeau</Button>
                        </template>
                    </div>
                </li>
            </ul>
            <HrPagination :paginator="referrals" />
        </Card>

        <ConfirmModal
            :open="giving !== null"
            title="Remettre le cadeau"
            :description="giving ? `${giving.referrer_name} a recommandé ${giving.patient?.name ?? 'un patient'}.` : ''"
            confirm-label="Marquer remis"
            tone="success"
            :icon="Gift"
            :processing="giftForm.processing"
            :dismissible="false"
            @update:open="(open) => open || giftForm.processing || (giving = null)"
            @confirm="confirmGift"
        >
            <div class="space-y-3">
                <p class="text-sm text-muted-foreground">Le cadeau ne se marque remis qu’une fois : la date et votre nom sont gardés.</p>
                <FormField label="Note" hint="(facultatif)">
                    <Textarea v-model="giftForm.note" :rows="2" maxlength="500" placeholder="Ex. bon d’achat remis en main propre" />
                </FormField>
                <p v-if="giftError" class="text-sm font-medium text-destructive">{{ giftError }}</p>
            </div>
        </ConfirmModal>
    </div>
</template>
