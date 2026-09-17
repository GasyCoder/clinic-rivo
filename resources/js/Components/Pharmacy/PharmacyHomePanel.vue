<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatNumber } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — the Pharmacy's daily tasks and stock watch list, shown on the
 * account's overview page. It used to be a separate « Accueil » page, which
 * duplicated the overview and made the pharmacist open two homes.
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    stockSummary: { type: Object, default: null },
    dispenseCount: { type: Number, default: 0 },
    careConsumableCount: { type: Number, default: 0 },
    alerts: { type: Array, default: () => [] },
});

// One tile per daily task, in the order a pharmacist meets them. Each tile
// is shown only to accounts allowed to open the screen behind it.
const actions = computed(() => [
    {
        show: props.capabilities.can_view_prescriptions,
        href: '/pharmacy/dispenses',
        icon: 'file-docs',
        title: 'Ordonnances à délivrer',
        text: 'Préparer et remettre les médicaments prescrits.',
        count: props.dispenseCount,
        tone: 'primary',
    },
    {
        show: props.capabilities.can_view_care_consumables,
        href: '/pharmacy/care-consumables',
        icon: 'user-check',
        title: 'Consommables Soins',
        text: 'Sortir du stock le matériel utilisé aux Soins.',
        count: props.careConsumableCount,
        tone: 'amber',
    },
    {
        show: props.capabilities.can_record_entry,
        href: '/pharmacy/stock/entries/create',
        icon: 'plus',
        title: 'Enregistrer une entrée',
        text: 'Ajouter au stock des médicaments reçus.',
        tone: 'sky',
    },
    {
        show: props.capabilities.can_view_stock,
        href: '/pharmacy/stock',
        icon: 'package',
        title: 'Voir le stock',
        text: 'Quantités disponibles, lots et péremptions.',
        tone: 'slate',
    },
    {
        show: props.capabilities.can_view_purchase_orders,
        href: '/pharmacy/purchases',
        icon: 'truck',
        title: 'Achats',
        text: 'Commander, réceptionner, enregistrer les factures.',
        tone: 'violet',
    },
].filter((action) => action.show));

const TILE_TONES = {
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    primary: 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    slate: 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
};

const watchList = computed(() => props.stockSummary ? [
    { label: 'Médicaments en rupture', value: props.stockSummary.out_of_stock ?? 0, href: '/pharmacy/stock?status=OUT_OF_STOCK', alarm: 'text-red-600' },
    { label: 'Péremption dans moins de 90 jours', value: props.stockSummary.expiring_soon ?? 0, href: '/pharmacy/stock?status=EXPIRING_SOON', alarm: 'text-amber-600' },
    { label: 'Lots déjà périmés', value: props.stockSummary.expired_lots ?? 0, href: '/pharmacy/stock', alarm: 'text-red-600' },
] : []);
</script>

<template>
    <section id="pharmacie" class="space-y-4" aria-labelledby="pharmacy-home-title">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 id="pharmacy-home-title" class="flex items-center gap-2 font-heading text-base font-bold text-foreground">
                    <Icon name="capsule" class="text-emerald-600" />Pharmacie — que voulez-vous faire ?
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Choisissez une tâche. L’encaissement et les reçus restent toujours à la Caisse.</p>
            </div>
        </div>

        <div v-if="actions.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="action in actions"
                :key="action.href"
                class="group overflow-hidden transition hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
            >
                <Link :href="action.href" class="flex h-full items-center gap-4 p-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring">
                    <span :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-2xl', TILE_TONES[action.tone]]">
                        <Icon :name="action.icon" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="font-heading text-base font-bold text-foreground">{{ action.title }}</span>
                            <Badge v-if="action.count" class="px-2 py-0.5">{{ action.count }}</Badge>
                        </span>
                        <span class="mt-0.5 block text-sm text-muted-foreground">{{ action.text }}</span>
                    </span>
                    <Icon name="chevron-right" class="text-lg text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                </Link>
            </Card>
        </div>

        <div v-if="watchList.length || capabilities.can_view_alerts" class="grid gap-4 lg:grid-cols-5">
            <Card v-if="watchList.length" class="p-5 lg:col-span-2">
                <h3 class="font-heading text-base font-bold text-foreground">À surveiller</h3>
                <ul class="mt-3 divide-y divide-border">
                    <li v-for="item in watchList" :key="item.label">
                        <Link :href="item.href" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary-600">
                            <span class="text-muted-foreground">{{ item.label }}</span>
                            <strong :class="['text-xl tabular-nums', item.value ? item.alarm : 'text-muted-foreground']">{{ formatNumber(item.value) }}</strong>
                        </Link>
                    </li>
                </ul>
            </Card>

            <Card v-if="capabilities.can_view_alerts" :class="['p-5', watchList.length ? 'lg:col-span-3' : 'lg:col-span-5']">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-heading text-base font-bold text-foreground">À recommander</h3>
                    <Badge v-if="alerts.length" variant="warning">{{ alerts.length }}</Badge>
                </div>
                <ul v-if="alerts.length" class="mt-3 divide-y divide-border">
                    <li v-for="alert in alerts.slice(0, 6)" :key="alert.uuid">
                        <Link :href="`/pharmacy/stock/${alert.medicine_uuid}`" class="flex items-center justify-between gap-3 py-3 text-sm hover:text-primary-600">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-foreground">{{ alert.medicine_name }}</span>
                                <span class="text-xs text-muted-foreground">{{ alert.type_label }}</span>
                            </span>
                            <span class="shrink-0 text-end text-xs text-muted-foreground">
                                <strong class="block text-base text-amber-600 tabular-nums">{{ alert.available_quantity }}</strong>
                                seuil {{ alert.threshold }}
                            </span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="mt-3 flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon name="check-circle" class="text-lg text-emerald-500" />Aucun médicament sous son seuil minimal.
                </p>
            </Card>
        </div>
    </section>
</template>
