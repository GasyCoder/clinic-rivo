<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronDown, ChevronUp, Eye, History } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDateTime } from '@/utilities/date';

const props = defineProps({
    history: { type: Array, default: () => [] },
    previousPregnancies: { type: Array, default: () => [] },
});

const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.history : props.history.slice(-4)));
</script>

<template>
    <Card v-if="history.length || previousPregnancies.length" class="overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><History class="h-4 w-4" />Consultations de cette grossesse</h2>
                <p class="mt-1 text-xs text-muted-foreground">Uniquement les passages rattachés à cette même grossesse.</p>
            </div>
            <Button v-if="history.length > 4" type="button" size="sm" variant="outline" @click="expanded = !expanded">
                <ChevronUp v-if="expanded" class="h-4 w-4" /><ChevronDown v-else class="h-4 w-4" />
                {{ expanded ? 'Réduire' : 'Voir tout l’historique' }}
            </Button>
        </header>

        <ol v-if="history.length" class="divide-y divide-border">
            <li v-for="visit in visible" :key="visit.uuid" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="flex items-start gap-3">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-bold text-muted-foreground">{{ visit.number }}</span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-foreground">{{ visit.label }}</p>
                            <Badge v-if="visit.is_current" tone="info">Actuelle</Badge>
                            <Badge v-else variant="outline">Lecture seule</Badge>
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ formatDateTime(visit.consultation_at) }} · {{ visit.episode_number }}<template v-if="visit.gestational_age_label"> · {{ visit.gestational_age_label }}</template></p>
                    </div>
                </div>
                <Button v-if="visit.url && !visit.is_current" :as="Link" :href="visit.url" size="sm" variant="outline"><Eye class="h-3.5 w-3.5" />Consulter</Button>
            </li>
        </ol>
        <p v-else class="px-5 py-4 text-xs text-muted-foreground">Aucune consultation encore enregistrée pour cette grossesse.</p>

        <div v-if="previousPregnancies.length" class="border-t border-border bg-muted/20 px-5 py-4">
            <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-muted-foreground"><CalendarDays class="h-4 w-4" />Grossesses précédentes</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <Badge v-for="previous in previousPregnancies" :key="previous.uuid" variant="outline" class="px-3 py-1.5">
                    {{ previous.reference }} · {{ previous.status_label }} · {{ previous.consultations_count }} consultation{{ previous.consultations_count > 1 ? 's' : '' }}
                </Badge>
            </div>
        </div>
    </Card>
</template>
