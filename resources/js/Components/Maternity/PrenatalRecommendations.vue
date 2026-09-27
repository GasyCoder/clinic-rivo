<script setup>
import { computed, onMounted, ref } from 'vue';
import { ChevronDown, CircleCheck, CircleDashed, Clock, FlaskConical, Hourglass, Info, Pin, PinOff, Plus, ScanLine } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';

/**
 * ADR-204 — ce qu'il est habituel de demander à ce terme.
 *
 * Des **rappels**, jamais des règles : rien n'est demandé d'office, rien
 * n'empêche de continuer ni de terminer. « Demander » ajoute l'examen à la
 * sélection, qui reste à confirmer comme toute demande (ADR-106). Tant que la
 * clinique n'a pas validé ce calendrier, l'écran le dit.
 *
 * Repliée par défaut : l'en-tête garde le terme et le compte des rappels. La
 * punaise la garde ouverte sur ce poste — une préférence d'affichage, jamais
 * envoyée au serveur.
 */
const props = defineProps({
    /** Servi par `PrenatalProtocolAdvisor`. */
    advice: { type: Object, default: null },
    canRequestLab: { type: Boolean, default: false },
    canRequestImaging: { type: Boolean, default: false },
});
defineEmits(['request']);

const STATUS = {
    DONE: { label: 'Fait', icon: CircleCheck, tone: 'success' },
    REQUESTED: { label: 'Demandé', icon: Hourglass, tone: 'warning' },
    NOT_REQUESTED: { label: 'Non demandé', icon: CircleDashed, tone: 'neutral' },
    UNKNOWN: { label: 'Non visible', icon: CircleDashed, tone: 'neutral' },
};
const TIMING = { UPCOMING: 'à venir', PAST: 'fenêtre passée', DUE: null };

const canRequest = (suggestion) => suggestion.catalog_item_uuid
    && suggestion.status === 'NOT_REQUESTED'
    && (suggestion.category === 'LAB' ? props.canRequestLab : props.canRequestImaging);
const pending = computed(() => props.advice?.pending_requests ?? []);
const suggestions = computed(() => props.advice?.suggestions ?? []);
const notRequested = computed(() => suggestions.value.filter((suggestion) => suggestion.status === 'NOT_REQUESTED').length);

const PIN_KEY = 'rivo:maternity:prenatal-advice-pinned';
const pinned = ref(false);
const open = ref(false);
// Rendu côté serveur : la préférence ne se lit qu'une fois la page reprise par le navigateur.
onMounted(() => {
    try { pinned.value = localStorage.getItem(PIN_KEY) === '1'; } catch { /* stockage indisponible */ }
    open.value = pinned.value;
});
const togglePin = () => {
    pinned.value = ! pinned.value;
    open.value = pinned.value;
    try {
        if (pinned.value) localStorage.setItem(PIN_KEY, '1');
        else localStorage.removeItem(PIN_KEY);
    } catch { /* stockage indisponible */ }
};
</script>

<template>
    <Card v-if="advice" class="p-4">
        <div class="flex items-start justify-between gap-2">
            <button
                type="button"
                class="flex min-w-0 flex-1 items-start gap-2 rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-expanded="open"
                aria-controls="prenatal-advice-body"
                @click="open = ! open"
            >
                <ChevronDown :class="['mt-0.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform', open ? '' : '-rotate-90']" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="block text-xs font-bold uppercase tracking-wide text-muted-foreground">Recommandé à cette période</span>
                    <span class="mt-0.5 block text-sm font-semibold text-foreground">
                        <template v-if="advice.current_period">{{ advice.current_period.label }} · {{ advice.current_period.range_label }}</template>
                        <template v-else>Terme non calculable : datez la grossesse pour afficher les rappels.</template>
                        <span v-if="advice.gestational_age_label" class="font-normal text-muted-foreground"> — aujourd’hui {{ advice.gestational_age_label }}</span>
                    </span>
                    <span v-if="! open && (suggestions.length || pending.length)" class="mt-0.5 block text-[11px] text-muted-foreground">
                        <template v-if="suggestions.length">{{ suggestions.length }} rappel{{ suggestions.length > 1 ? 's' : '' }}<template v-if="notRequested"> · {{ notRequested }} non demandé{{ notRequested > 1 ? 's' : '' }}</template></template>
                        <template v-if="pending.length"><template v-if="suggestions.length"> · </template>{{ pending.length }} en attente de résultat</template>
                        · Afficher
                    </span>
                </span>
            </button>
            <div class="flex shrink-0 items-center gap-1.5">
                <Badge v-if="! advice.validated" tone="warning" class="gap-1 px-2 py-0.5 text-[10px]"><Info class="h-3 w-3" aria-hidden="true" />À valider par la clinique</Badge>
                <Button
                    type="button"
                    size="icon-xs"
                    :variant="pinned ? 'secondary' : 'ghost'"
                    :aria-pressed="pinned"
                    :aria-label="pinned ? 'Détacher : la section sera repliée par défaut' : 'Épingler : garder cette section ouverte'"
                    :title="pinned ? 'Détacher : la section sera repliée par défaut' : 'Épingler : garder cette section ouverte'"
                    @click="togglePin"
                >
                    <component :is="pinned ? PinOff : Pin" class="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>

        <div v-show="open" id="prenatal-advice-body">
            <p class="mt-1 text-[11px] leading-4 text-muted-foreground">{{ advice.notice }}</p>

            <ul v-if="advice.suggestions.length" class="mt-3 grid gap-2 md:grid-cols-2">
                <li v-for="suggestion in advice.suggestions" :key="`${suggestion.category}-${suggestion.code}`" class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2">
                    <component :is="suggestion.category === 'LAB' ? FlaskConical : ScanLine" class="h-4 w-4 shrink-0 text-muted-foreground" :aria-label="suggestion.category === 'LAB' ? 'Analyse' : 'Imagerie'" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-foreground" :title="suggestion.label">{{ suggestion.label }}</p>
                        <p class="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
                            <Clock class="h-3 w-3" aria-hidden="true" />{{ suggestion.window_label }}
                            <template v-if="TIMING[suggestion.timing]"> · {{ TIMING[suggestion.timing] }}</template>
                            <template v-if="! suggestion.catalog_item_uuid"> · absent du catalogue du site</template>
                        </p>
                    </div>
                    <Badge :tone="STATUS[suggestion.status]?.tone" class="gap-1 px-1.5 py-0 text-[10px]">
                        <component :is="STATUS[suggestion.status]?.icon" class="h-3 w-3" aria-hidden="true" />{{ STATUS[suggestion.status]?.label }}
                    </Badge>
                    <Button
                        v-if="canRequest(suggestion)"
                        type="button"
                        size="xs"
                        variant="outline"
                        :aria-label="`Demander ${suggestion.label}`"
                        @click="$emit('request', suggestion)"
                    >
                        <Plus class="h-3.5 w-3.5" />Demander
                    </Button>
                </li>
            </ul>

            <p v-if="pending.length" class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                <Hourglass class="h-3.5 w-3.5" aria-hidden="true" />
                <span class="font-semibold text-foreground">En attente de résultat :</span>
                <span v-for="(item, index) in pending" :key="index">{{ item.exam }}<template v-if="index < pending.length - 1">,</template></span>
            </p>
        </div>
    </Card>
</template>
