<script setup>
import { CalendarPlus, CircleCheck, Link2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { formatDate, formatDateTime } from '@/utilities/date';

defineProps({
    activePregnancies: { type: Array, default: () => [] },
    choice: { type: String, default: '' },
    selectedUuid: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
defineEmits(['continue', 'create']);
</script>

<template>
    <Card class="border-rose-200 p-5 dark:border-rose-900">
        <div class="flex items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"><Link2 class="h-5 w-5" /></span>
            <div>
                <h2 class="text-sm font-bold text-foreground">Relier cette consultation à une grossesse</h2>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">Ce choix est obligatoire et explicite. Le passage reste distinct de la grossesse longitudinale.</p>
            </div>
        </div>

        <div v-if="activePregnancies.length" class="mt-4 space-y-3">
            <div v-for="pregnancy in activePregnancies" :key="pregnancy.uuid" class="rounded-lg border border-border bg-muted/25 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-bold text-foreground">{{ pregnancy.reference }}</p>
                            <Badge tone="info">Grossesse active</Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            DPA {{ formatDate(pregnancy.estimated_due_date) ?? 'non renseignée' }}
                            · dernière consultation {{ formatDateTime(pregnancy.last_consultation_at) ?? 'aucune' }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        :variant="choice === 'CONTINUE' && selectedUuid === pregnancy.uuid ? 'success' : 'primary'"
                        :disabled="disabled"
                        @click="$emit('continue', pregnancy.uuid)"
                    >
                        <CircleCheck v-if="choice === 'CONTINUE' && selectedUuid === pregnancy.uuid" class="h-4 w-4" />
                        <Link2 v-else class="h-4 w-4" />
                        {{ choice === 'CONTINUE' && selectedUuid === pregnancy.uuid ? 'Grossesse sélectionnée' : 'Continuer cette grossesse' }}
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">Une grossesse active existe : elle doit être terminée ou livrée avant d’en créer une autre.</p>
        </div>

        <div v-else class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-border bg-muted/20 p-4">
            <div>
                <p class="text-sm font-semibold text-foreground">Aucune grossesse active trouvée</p>
                <p class="mt-1 text-xs text-muted-foreground">Créez le dossier longitudinal, puis renseignez sa DDR et ses données obstétricales.</p>
            </div>
            <Button type="button" size="sm" :variant="choice === 'CREATE' ? 'success' : 'primary'" :disabled="disabled" @click="$emit('create')">
                <CircleCheck v-if="choice === 'CREATE'" class="h-4 w-4" />
                <CalendarPlus v-else class="h-4 w-4" />
                {{ choice === 'CREATE' ? 'Nouvelle grossesse sélectionnée' : 'Créer une nouvelle grossesse' }}
            </Button>
        </div>
    </Card>
</template>
