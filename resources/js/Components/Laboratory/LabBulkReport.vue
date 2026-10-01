<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';

/**
 * ADR-220 — le rapport d'une action groupée : ce qui est passé, ce qui ne l'est
 * pas, et pourquoi (même principe que « Sorties & règlements », ADR-090).
 */
const page = usePage();
const report = computed(() => page.props.flash?.bulk_report ?? null);
const hidden = ref(false);
watch(report, () => { hidden.value = false; });

const WHAT = { archive: 'archivée', unarchive: 'sortie des archives', trash: 'mise à la corbeille' };
const summary = computed(() => {
    if (!report.value) return '';
    const { done, total, action } = report.value;

    return `${done} sur ${total} demande${total > 1 ? 's' : ''} ${WHAT[action] ?? 'traitée'}${done > 1 && action !== 'unarchive' ? 's' : ''}`;
});
</script>

<template>
    <div v-if="report && report.action in WHAT && !hidden" class="rounded-lg border border-border bg-card px-4 py-3 shadow-sm print:hidden" role="status">
        <div class="flex items-start gap-3">
            <component :is="report.failed.length === 0 ? CircleCheck : CircleAlert" :class="['mt-0.5 h-5 w-5 shrink-0', report.failed.length === 0 ? 'text-emerald-600' : 'text-amber-600']" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-foreground">{{ summary }}</p>
                <p v-if="report.invoiced" class="mt-0.5 text-xs text-muted-foreground">{{ report.invoiced }} analyse{{ report.invoiced > 1 ? 's étaient' : ' était' }} déjà sur une facture : la Caisse régularise.</p>
                <ul v-if="report.failed.length" class="mt-2 space-y-1 text-sm">
                    <li v-for="(failure, index) in report.failed" :key="index" class="text-muted-foreground">
                        <span class="font-medium text-foreground">{{ failure.label }}</span> — {{ failure.message }}
                    </li>
                </ul>
            </div>
            <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer le rapport" @click="hidden = true"><X class="h-4 w-4" /></Button>
        </div>
    </div>
</template>
