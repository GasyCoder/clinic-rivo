<script setup>
import { computed } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import { cn } from '@/lib/cn';
import { AlertTriangle, Info, Stethoscope, Syringe, ClipboardCheck } from 'lucide-vue-next';

/**
 * ADR-170 — ce qui empêche le geste, dit en toutes lettres.
 *
 * Un bouton grisé sans explication fait chercher une panne là où il y a une
 * règle. Chaque constat nomme **le métier à qui il appartient** : le
 * chirurgien lit « en attente de l'anesthésiste » au lieu de se voir proposer
 * un geste qui n'est pas le sien.
 *
 * La couleur ne porte jamais seule l'information (contrainte du propriétaire) :
 * chaque ligne a son icône et son libellé écrit.
 */
const props = defineProps({
    /** @type {{key: string, owner: string, message: string, hint: ?string}[]} */
    issues: { type: Array, default: () => [] },
    /** blocking · warning */
    level: { type: String, default: 'blocking' },
    title: { type: String, default: '' },
});

const OWNERS = {
    SURGERY: { label: 'Équipe chirurgicale', icon: Stethoscope },
    ANESTHESIA: { label: 'Anesthésie', icon: Syringe },
    BLOCK: { label: 'Bloc', icon: ClipboardCheck },
};

const blocking = computed(() => props.level === 'blocking');
const heading = computed(() => props.title || (blocking.value
    ? (props.issues.length === 1 ? '1 blocage avant l’intervention' : `${props.issues.length} blocages avant l’intervention`)
    : 'À vérifier — sans blocage'));
</script>

<template>
    <div
        v-if="issues.length"
        :class="cn(
            'rounded-lg border p-3.5',
            blocking
                ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30'
                : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30',
        )"
        role="alert"
    >
        <p :class="cn('flex items-center gap-2 text-sm font-semibold', blocking ? 'text-red-800 dark:text-red-200' : 'text-amber-800 dark:text-amber-200')">
            <component :is="blocking ? AlertTriangle : Info" class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ heading }}
        </p>

        <ul class="mt-2.5 space-y-2">
            <li v-for="issue in issues" :key="issue.key" class="flex flex-wrap items-start gap-x-2 gap-y-1">
                <Badge :variant="blocking ? 'destructive' : 'warning'" class="mt-0.5 shrink-0">
                    <component :is="(OWNERS[issue.owner] ?? OWNERS.BLOCK).icon" class="h-3 w-3" aria-hidden="true" />
                    {{ (OWNERS[issue.owner] ?? OWNERS.BLOCK).label }}
                </Badge>
                <span class="min-w-0 flex-1 basis-56">
                    <span :class="cn('block text-sm', blocking ? 'text-red-900 dark:text-red-100' : 'text-amber-900 dark:text-amber-100')">{{ issue.message }}</span>
                    <span v-if="issue.hint" class="mt-0.5 block text-xs text-muted-foreground">{{ issue.hint }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
