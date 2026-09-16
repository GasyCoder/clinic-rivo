<script setup>
import { computed } from 'vue';
import { TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { cn } from '@/lib/cn';

/**
 * Une permission et l'exception que ce compte porte dessus.
 *
 * Trois états, jamais deux : « Selon le rôle » n'est pas une absence de
 * décision, c'est le socle du rôle qui s'applique — vert quand ce socle
 * accorde le droit, gris quand il ne l'accorde pas. Les deux autres sont
 * des exceptions propres au compte, et une interdiction l'emporte toujours
 * (ADR-022, ADR-033).
 */
const props = defineProps({
    permission: { type: Object, required: true },
    state: { type: String, default: '' },
    roleGranted: { type: Boolean, default: false },
    effectiveGranted: { type: Boolean, default: false },
    sensitive: { type: Boolean, default: false },
    advanced: { type: Boolean, default: false },
    sourceLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['change']);

const choices = [
    { value: '', label: 'Selon le rôle' },
    { value: 'allow', label: 'Autoriser' },
    { value: 'deny', label: 'Interdire' },
];

const rowClass = computed(() => cn(
    'grid gap-3 border-b border-border/60 px-4 py-3 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center',
    props.state === 'allow' ? 'bg-emerald-50/40 dark:bg-emerald-950/15'
        : props.state === 'deny' ? 'bg-red-50/40 dark:bg-red-950/15'
            : props.roleGranted ? 'bg-emerald-50/20 dark:bg-emerald-950/5' : '',
));

const choiceClass = (value) => {
    const selected = props.state === value;

    if (! selected) {
        return 'text-muted-foreground hover:bg-card hover:text-foreground';
    }

    if (value === 'allow') return 'bg-emerald-600 text-white shadow-sm';
    if (value === 'deny') return 'bg-destructive text-destructive-foreground shadow-sm';

    return props.roleGranted
        ? 'bg-emerald-50 text-emerald-700 shadow-sm ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
        : 'bg-card text-foreground shadow-sm';
};
</script>

<template>
    <div :class="rowClass">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-1.5">
                <p class="text-sm font-semibold text-foreground">{{ permission.label }}</p>
                <Badge v-if="sensitive" variant="warning" class="px-1.5 py-0 text-[10px] uppercase tracking-wide">
                    <TriangleAlert class="h-3 w-3" />Sensible
                </Badge>
                <Badge v-if="state === '' && roleGranted" variant="success" class="px-1.5 py-0 text-[10px]">Inclus dans le rôle</Badge>
                <Badge v-else-if="state === ''" variant="outline" class="px-1.5 py-0 text-[10px]">Non inclus dans le rôle</Badge>
                <Badge v-else-if="state === 'allow'" variant="success" class="px-1.5 py-0 text-[10px]">Exception · Autorisé</Badge>
                <Badge v-else variant="destructive" class="px-1.5 py-0 text-[10px]">Exception · Interdit</Badge>
            </div>
            <p v-if="advanced" class="mt-1 truncate font-mono text-[11px] text-muted-foreground" :title="permission.name">{{ permission.name }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                <span>Rôle : <strong :class="roleGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-foreground'">{{ roleGranted ? 'autorisé' : 'interdit' }}</strong></span>
                <span>Effectif : <strong :class="effectiveGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'">{{ effectiveGranted ? 'autorisé' : 'interdit' }}</strong></span>
                <span v-if="sourceLabel" class="text-primary">{{ sourceLabel }}</span>
            </div>
        </div>

        <fieldset class="min-w-0" :disabled="disabled">
            <legend class="sr-only">Accès pour {{ permission.label }}</legend>
            <div class="inline-flex w-full rounded-lg border border-border bg-muted p-0.5 sm:w-auto" role="radiogroup" :aria-label="`Accès pour ${permission.label}`">
                <button
                    v-for="choice in choices"
                    :key="choice.value || 'inherit'"
                    type="button"
                    role="radio"
                    :aria-checked="state === choice.value"
                    :disabled="disabled"
                    :class="cn(
                        'min-h-8 flex-1 rounded-md px-2.5 py-1 text-[11px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:flex-none',
                        choiceClass(choice.value),
                    )"
                    @click="$emit('change', choice.value)"
                >
                    {{ choice.label }}
                </button>
            </div>
        </fieldset>
    </div>
</template>
