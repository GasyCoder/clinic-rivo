<script setup>
import { CalendarDays, History, Pencil, ShieldAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDate, formatDateTime } from '@/utilities/date';

defineProps({
    pregnancy: { type: Object, required: true },
    canCorrectDating: { type: Boolean, default: false },
});
defineEmits(['correct-dating']);
</script>

<template>
    <Card class="overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                    <CalendarDays class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Grossesse actuelle</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-bold text-foreground">{{ pregnancy.reference }}</h2>
                        <Badge :tone="pregnancy.status === 'ONGOING' ? 'info' : 'success'">{{ pregnancy.status_label }}</Badge>
                    </div>
                </div>
            </div>
            <Button v-if="canCorrectDating" type="button" size="sm" variant="outline" @click="$emit('correct-dating')">
                <Pencil class="h-3.5 w-3.5" />Corriger la datation
            </Button>
        </div>

        <dl class="grid gap-px bg-border sm:grid-cols-2 xl:grid-cols-5">
            <div class="bg-card px-5 py-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">DDR</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ formatDate(pregnancy.last_menstrual_period) ?? 'Non renseignée' }}</dd>
            </div>
            <div class="bg-card px-5 py-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">DPA</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ formatDate(pregnancy.estimated_due_date) ?? 'Non renseignée' }}</dd>
            </div>
            <div class="bg-card px-5 py-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Terme au passage</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ pregnancy.gestational_age_label ?? 'Non calculable' }}</dd>
            </div>
            <div class="bg-card px-5 py-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Consultations</dt>
                <dd class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-foreground"><History class="h-3.5 w-3.5" />{{ pregnancy.consultations_count }}</dd>
            </div>
            <div class="bg-card px-5 py-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Dernière consultation</dt>
                <dd class="mt-1 text-sm font-semibold text-foreground">{{ formatDateTime(pregnancy.last_consultation_at) ?? 'Aucune' }}</dd>
            </div>
        </dl>

        <div v-if="pregnancy.risk_factors" class="flex items-start gap-2 border-t border-border bg-amber-50/60 px-5 py-3 text-xs text-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p><strong>Facteurs de risque consignés :</strong> {{ pregnancy.risk_factors }}</p>
        </div>
    </Card>
</template>
