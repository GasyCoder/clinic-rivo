<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Baby, CalendarDays, ChevronDown, ChevronUp, Eye, History, Scale } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import PrenatalComparisonCard from '@/Components/Maternity/PrenatalComparisonCard.vue';
import { formatDate, formatDateTime } from '@/utilities/date';

/**
 * Le suivi de la grossesse, en une carte à onglets (ADR-201).
 *
 * Empilés, l'historique, la comparaison et les grossesses précédentes
 * repoussaient le formulaire du jour loin sous la ligne de flottaison. Les
 * trois ensembles restent distincts — jamais un mélange de toutes les
 * consultations de la patiente — et chacun se lit d'un clic.
 */
const props = defineProps({
    history: { type: Array, default: () => [] },
    previousPregnancies: { type: Array, default: () => [] },
    /** Comparaison factuelle servie par le serveur (`PrenatalComparisonPresenter`). */
    comparison: { type: Object, default: null },
});

const tab = ref('visits');
const expanded = ref(false);
// Les plus récentes d'abord à l'écran de soin : c'est la dernière qu'on relit.
const visible = computed(() => (expanded.value ? props.history : props.history.slice(-4)));
const openPrevious = ref(null);
</script>

<template>
    <Card v-if="history.length || previousPregnancies.length || comparison" class="overflow-hidden">
        <Tabs v-model="tab">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                <div class="min-w-0">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><History class="h-4 w-4" aria-hidden="true" />Suivi de la grossesse</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Uniquement les passages rattachés à cette même grossesse.</p>
                </div>
                <TabsList class="h-auto flex-wrap">
                    <TabsTrigger value="visits" class="text-xs"><History class="h-3.5 w-3.5" aria-hidden="true" />Consultations de cette grossesse · {{ history.length }}</TabsTrigger>
                    <TabsTrigger v-if="comparison" value="comparison" class="text-xs"><Scale class="h-3.5 w-3.5" aria-hidden="true" />Comparaison</TabsTrigger>
                    <TabsTrigger v-if="previousPregnancies.length" value="previous" class="text-xs"><CalendarDays class="h-3.5 w-3.5" aria-hidden="true" />Grossesses précédentes · {{ previousPregnancies.length }}</TabsTrigger>
                </TabsList>
            </header>

            <TabsContent value="visits" class="mt-0">
                <ol v-if="history.length" class="divide-y divide-border">
                    <li v-for="visit in visible" :key="visit.uuid" class="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-xs font-bold text-muted-foreground">{{ visit.number }}</span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-foreground">{{ visit.label }}</p>
                                    <Badge v-if="visit.is_current" tone="info">Actuelle</Badge>
                                    <Badge v-else variant="outline">Lecture seule</Badge>
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ formatDateTime(visit.consultation_at) }} · {{ visit.episode_number }}<template v-if="visit.gestational_age_label"> · {{ visit.gestational_age_label }}</template></p>
                            </div>
                        </div>
                        <Button v-if="visit.url && !visit.is_current" :as="Link" :href="visit.url" size="sm" variant="outline"><Eye class="h-3.5 w-3.5" aria-hidden="true" />Consulter</Button>
                    </li>
                </ol>
                <p v-else class="px-5 py-4 text-xs text-muted-foreground">Aucune consultation encore enregistrée pour cette grossesse.</p>
                <div v-if="history.length > 4" class="border-t border-border px-5 py-2">
                    <Button type="button" size="sm" variant="ghost" @click="expanded = !expanded">
                        <ChevronUp v-if="expanded" class="h-4 w-4" aria-hidden="true" /><ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                        {{ expanded ? 'Réduire' : `Voir tout l’historique (${history.length})` }}
                    </Button>
                </div>
            </TabsContent>

            <TabsContent v-if="comparison" value="comparison" class="mt-0">
                <PrenatalComparisonCard :comparison="comparison" bare />
            </TabsContent>

            <!-- Les grossesses terminées, à part : leurs consultations ne se mêlent
                 jamais à celles de la grossesse en cours. -->
            <TabsContent v-if="previousPregnancies.length" value="previous" class="mt-0">
                <ul class="divide-y divide-border">
                    <li v-for="previous in previousPregnancies" :key="previous.uuid" class="px-5 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                                    {{ previous.reference }}
                                    <Badge :tone="previous.status === 'DELIVERED' ? 'success' : 'neutral'">{{ previous.status_label }}</Badge>
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                    <template v-if="previous.delivered_at"><Baby class="h-3.5 w-3.5" aria-hidden="true" />Accouchement le {{ formatDateTime(previous.delivered_at) }}</template>
                                    <template v-else-if="previous.ended_at">Terminée le {{ formatDate(previous.ended_at) }}</template>
                                    <span>· {{ previous.consultations_count }} consultation{{ previous.consultations_count > 1 ? 's' : '' }}</span>
                                </p>
                            </div>
                            <Button
                                v-if="previous.history?.length"
                                type="button"
                                size="sm"
                                variant="outline"
                                :aria-expanded="openPrevious === previous.uuid"
                                @click="openPrevious = openPrevious === previous.uuid ? null : previous.uuid"
                            >
                                <ChevronUp v-if="openPrevious === previous.uuid" class="h-4 w-4" aria-hidden="true" /><ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                                Ses consultations
                            </Button>
                        </div>
                        <ol v-if="openPrevious === previous.uuid" class="mt-2 divide-y divide-border rounded-md border border-border">
                            <li v-for="visit in previous.history" :key="visit.uuid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
                                <span class="text-muted-foreground"><span class="font-semibold text-foreground">{{ visit.label }}</span> · {{ formatDateTime(visit.consultation_at) }}<template v-if="visit.gestational_age_label"> · {{ visit.gestational_age_label }}</template></span>
                                <Button v-if="visit.url" :as="Link" :href="visit.url" size="sm" variant="ghost"><Eye class="h-3.5 w-3.5" aria-hidden="true" />Consulter</Button>
                            </li>
                        </ol>
                    </li>
                </ul>
            </TabsContent>
        </Tabs>
    </Card>
</template>
