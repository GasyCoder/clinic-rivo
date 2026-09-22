<script setup>
import { computed } from 'vue';
import { TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import { permissionLabel } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Une permission et l'exception que ce compte porte dessus.
 *
 * Trois états, jamais deux : « Suivre le rôle » n'est pas une absence de
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

/**
 * Chaque bouton dit ce qu'il produit, pas seulement ce qu'il écrit :
 * « Suivre le rôle » annonce le résultat que le socle donne à ce compte,
 * les deux exceptions annoncent qu'elles s'appliquent malgré le socle.
 */
const choices = computed(() => [
    {
        value: '',
        label: 'Suivre le rôle',
        hint: props.roleGranted ? 'le rôle l’accorde → accès' : 'le rôle ne l’accorde pas → pas d’accès',
    },
    {
        value: 'allow',
        label: 'Toujours autoriser',
        hint: props.roleGranted ? 'inutile : le rôle l’accorde déjà' : 'même si le rôle ne l’accorde pas',
    },
    {
        value: 'deny',
        label: 'Toujours interdire',
        hint: props.roleGranted ? 'même si le rôle l’accorde' : 'verrouillé, même si le rôle l’accorde un jour',
    },
]);

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
                <p class="text-sm font-semibold text-foreground">{{ permissionLabel(permission) }}</p>
                <Badge v-if="sensitive" variant="warning" class="px-1.5 py-0 text-[10px] uppercase tracking-wide">
                    <TriangleAlert class="h-3 w-3" />Sensible
                </Badge>
                <Badge v-if="state === '' && roleGranted" variant="success" class="px-1.5 py-0 text-[10px]">Inclus dans le rôle</Badge>
                <Badge v-else-if="state === ''" variant="outline" class="px-1.5 py-0 text-[10px]">Non inclus dans le rôle</Badge>
                <Badge v-else-if="state === 'allow'" variant="success" class="px-1.5 py-0 text-[10px]">Exception : toujours autorisé</Badge>
                <Badge v-else variant="destructive" class="px-1.5 py-0 text-[10px]">Exception : toujours interdit</Badge>
            </div>
            <p v-if="advanced" class="mt-1 truncate font-mono text-[11px] text-muted-foreground" :title="permission.name">{{ permission.name }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                <span>Socle du rôle : <strong :class="roleGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-foreground'">{{ roleGranted ? 'accorde ce droit' : 'n’accorde pas ce droit' }}</strong></span>
                <span>Résultat pour ce compte : <strong :class="effectiveGranted ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'">{{ effectiveGranted ? 'accès' : 'pas d’accès' }}</strong></span>
                <span v-if="sourceLabel" class="text-primary">{{ sourceLabel }}</span>
            </div>
        </div>

        <fieldset class="min-w-0" :disabled="disabled">
            <legend class="sr-only">Accès pour {{ permissionLabel(permission) }}</legend>
            <div class="inline-flex w-full rounded-lg border border-border bg-muted p-0.5 sm:w-auto" role="radiogroup" :aria-label="`Accès pour ${permissionLabel(permission)}`">
                <button
                    v-for="choice in choices"
                    :key="choice.value || 'inherit'"
                    type="button"
                    role="radio"
                    :aria-checked="state === choice.value"
                    :disabled="disabled"
                    :title="choice.hint"
                    :class="cn(
                        'flex min-h-8 flex-1 flex-col items-center rounded-md px-2.5 py-1 leading-tight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:flex-none',
                        choiceClass(choice.value),
                    )"
                    @click="$emit('change', choice.value)"
                >
                    <span class="text-[11px] font-bold">{{ choice.label }}</span>
                    <span class="text-[9.5px] font-normal opacity-80">{{ choice.hint }}</span>
                </button>
            </div>
        </fieldset>
    </div>
</template>
