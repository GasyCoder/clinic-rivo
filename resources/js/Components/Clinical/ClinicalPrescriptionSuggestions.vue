<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BookMarked, CircleAlert, History, Info, ListPlus, Plus, Sparkles, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';

/**
 * L'ordonnance proposée pour les diagnostics posés (ADR-111).
 *
 * Deux sources locales, dans cet ordre : le protocole de la clinique quand il
 * s'applique, sinon ce que ses médecins ont prescrit dans des cas semblables.
 *
 * « Ajouter » ne prescrit rien : la ligne rejoint la préparation, où le
 * médecin règle dose, durée et quantité, puis valide — et signe (ADR-106).
 * Trois cas ne sont jamais ajoutés d'un seul geste :
 *
 *   - un produit épuisé, qu'une ordonnance ne pourrait pas réserver ;
 *   - une allergie connue recoupée ;
 *   - un patient hors de la tranche d'âge déjà traitée par la clinique — une
 *     dose d'adulte ne se transpose pas à un enfant.
 *
 * Les deux derniers restent ajoutables ligne par ligne, après avoir été vus.
 */
const props = defineProps({
    prescription: { type: Object, default: () => ({ groups: [], excluded: [] }) },
    selectedUuids: { type: Object, default: () => new Set() },
    protocolCount: { type: Number, default: 0 },
    practiceCases: { type: Number, default: 0 },
    practiceMinCases: { type: Number, default: 3 },
    hasDiagnosis: { type: Boolean, default: false },
    canManageProtocols: { type: Boolean, default: false },
    routes: { type: Array, default: () => [] },
});

const emit = defineEmits(['add']);

const groups = computed(() => props.prescription?.groups ?? []);
const excluded = computed(() => props.prescription?.excluded ?? []);

const posology = (line) => [
    line.dosage,
    props.routes.find((route) => route.value === line.route)?.short_label,
    line.frequency,
    line.duration,
].filter(Boolean).join(' · ');

const alreadyAdded = (line) => props.selectedUuids.has(line.medicine_uuid);
const addable = (line) => line.available && !alreadyAdded(line);
const needsCare = (line) => Boolean(line.allergy_conflict || line.age_note);

/** « Tout ajouter » ne prend jamais une ligne qui demande un regard. */
const bulkLines = (group) => group.lines.filter((line) => addable(line) && !needsCare(line));
const addAll = (group) => bulkLines(group).forEach((line) => emit('add', line, group));

const nothingToLearnFrom = computed(() => props.protocolCount === 0 && props.practiceCases < props.practiceMinCases);
</script>

<template>
    <section v-if="groups.length || excluded.length" class="rounded-xl border border-primary/25 bg-primary/5 p-3" aria-label="Ordonnance proposée">
        <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.12em] text-primary">
            <Sparkles class="h-3.5 w-3.5" aria-hidden="true" />Ordonnance proposée
        </p>
        <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">
            D’après les diagnostics posés, les protocoles et la pratique de la clinique — calculé sur place. Chaque ligne ajoutée reste modifiable avant validation.
        </p>

        <article v-for="group in groups" :key="group.key" class="mt-3 rounded-lg border border-border bg-card">
            <header class="flex flex-wrap items-start justify-between gap-2 border-b border-border px-3 py-2.5">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-foreground">{{ group.name }}</span>
                        <Badge v-if="group.source === 'PROTOCOL'" tone="info" class="px-2 py-0.5 text-[10px]"><BookMarked class="h-3 w-3" aria-hidden="true" />Protocole</Badge>
                        <Badge v-else tone="neutral" class="px-2 py-0.5 text-[10px]"><History class="h-3 w-3" aria-hidden="true" />Historique</Badge>
                    </p>
                    <p class="text-[11px] text-muted-foreground">{{ group.diagnosis }}</p>
                </div>
                <Button type="button" size="xs" variant="white-outline" :disabled="bulkLines(group).length === 0" @click="addAll(group)">
                    <ListPlus class="h-3.5 w-3.5" aria-hidden="true" />Tout ajouter
                </Button>
            </header>

            <p v-if="group.notes" class="flex items-start gap-1.5 border-b border-border bg-muted/40 px-3 py-2 text-[11px] leading-4 text-muted-foreground">
                <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ group.notes }}
            </p>

            <ul class="divide-y divide-border">
                <li v-for="line in group.lines" :key="line.medicine_uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-1.5">
                            <strong class="text-sm font-semibold text-foreground">{{ line.medicine_name }}</strong>
                            <Badge v-if="line.support" tone="neutral" class="px-2 py-0.5 text-[10px] tabular-nums">{{ line.support.count }}/{{ line.support.cases }} consultations</Badge>
                            <Badge v-if="line.allergy_conflict" tone="danger" class="px-2 py-0.5 text-[10px]">
                                <CircleAlert class="h-3 w-3" aria-hidden="true" />Allergie : {{ line.allergy_conflict }}
                            </Badge>
                            <Badge v-if="!line.available" tone="danger" class="px-2 py-0.5 text-[10px]">Épuisé</Badge>
                            <Badge v-else-if="alreadyAdded(line)" tone="success" class="px-2 py-0.5 text-[10px]">Dans l’ordonnance</Badge>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ posology(line) || 'Posologie à préciser' }}<template v-if="line.quantity"> · {{ line.quantity }} {{ line.unit || 'unité(s)' }}</template>
                        </p>
                        <p v-if="line.age_note" class="mt-1 flex items-start gap-1.5 text-[11px] font-medium leading-4 text-amber-700 dark:text-amber-300">
                            <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ line.age_note }}
                        </p>
                    </div>
                    <Button
                        v-if="addable(line)"
                        type="button"
                        size="xs"
                        :variant="line.allergy_conflict ? 'danger-outline' : (line.age_note ? 'warning-outline' : 'default')"
                        @click="emit('add', line, group)"
                    >
                        <Plus class="h-3.5 w-3.5" aria-hidden="true" />{{ line.allergy_conflict ? 'Ajouter malgré l’allergie' : (line.age_note ? 'Ajouter et vérifier la dose' : 'Ajouter') }}
                    </Button>
                </li>
            </ul>
        </article>

        <!-- Un protocole écarté n'est pas tu : sans la raison, le médecin
             conclurait qu'il n'en existe aucun pour ce diagnostic. -->
        <ul v-if="excluded.length" class="mt-3 space-y-1">
            <li v-for="item in excluded" :key="item.protocol_uuid" class="flex items-start gap-1.5 text-[11px] leading-4 text-muted-foreground">
                <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span><strong class="font-semibold text-foreground">{{ item.name }}</strong> non proposé — {{ item.reason }}</span>
            </li>
        </ul>
    </section>

    <p v-else-if="!hasDiagnosis" class="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-[11px] leading-4 text-muted-foreground">
        <Sparkles class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Posez un diagnostic : l’ordonnance habituelle de la clinique vous sera proposée ici.
    </p>
    <p v-else-if="nothingToLearnFrom" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-dashed border-border px-3 py-2 text-[11px] leading-4 text-muted-foreground">
        <BookMarked class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Aucune ordonnance proposée : aucun protocole rédigé, et pas encore assez de consultations conclues pour apprendre de la pratique de la clinique.
        <Link v-if="canManageProtocols" href="/medicine/protocoles/nouveau" class="font-semibold text-primary hover:underline">Rédiger un protocole</Link>
    </p>
</template>
