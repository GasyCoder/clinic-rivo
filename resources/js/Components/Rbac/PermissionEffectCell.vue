<script setup>
import { computed } from 'vue';
import { Check, ShieldCheck, ShieldPlus, ShieldX, TriangleAlert, X } from 'lucide-vue-next';
import {
    DropdownMenuContent,
    DropdownMenuItemIndicator,
    DropdownMenuPortal,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { permissionLabel } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Une permission vue depuis un compte : ce que son rôle lui donne, et
 * l'exception qu'il porte peut-être par-dessus (ADR-022, ADR-033, ADR-178).
 *
 * La case montre le résultat, pas le réglage : verte quand le compte a
 * l'accès, rouge quand une interdiction le lui retire, vide sinon. Pleine
 * quand c'est une exception, pâle quand c'est le rôle qui décide — on lit la
 * différence sans rien ouvrir.
 *
 * Le réglage s'ouvre au clic. Trois états, jamais deux : « Suivre le rôle »
 * n'est pas une absence de décision, c'est le socle qui s'applique.
 *
 *     DENY individuel  >  ALLOW individuel  >  socle du rôle
 */
const props = defineProps({
    permission: { type: Object, required: true },
    /** '' (suivre le rôle), 'allow' ou 'deny'. */
    effect: { type: String, default: '' },
    roleGranted: { type: Boolean, default: false },
    changed: { type: Boolean, default: false },
    sensitive: { type: Boolean, default: false },
    dimmed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    presentation: { type: String, default: 'box' },
    text: { type: String, default: '' },
    context: { type: String, default: '' },
    /** « Profil Infirmier » ou « Décision individuelle » : d'où vient l'exception enregistrée. */
    sourceLabel: { type: String, default: '' },
});

const emit = defineEmits(['change']);

const label = computed(() => permissionLabel(props.permission));

/** Un DENY individuel l'emporte toujours, y compris sur le socle du rôle. */
const effective = computed(() => {
    if (props.effect === 'deny') return false;
    if (props.effect === 'allow') return true;

    return props.roleGranted;
});

const state = computed(() => {
    if (props.effect === 'deny') return { text: 'Toujours interdit (exception)', tone: 'deny' };
    if (props.effect === 'allow') return { text: 'Toujours autorisé (exception)', tone: 'allow' };

    return props.roleGranted
        ? { text: 'Accordé par le rôle', tone: 'role' }
        : { text: 'Pas d’accès (le rôle ne l’accorde pas)', tone: 'none' };
});

/** Chaque choix dit ce qu'il produit pour ce compte, pas seulement ce qu'il écrit. */
const choices = computed(() => [
    {
        value: '',
        label: 'Suivre le rôle',
        hint: props.roleGranted ? 'Le rôle l’accorde : accès' : 'Le rôle ne l’accorde pas : pas d’accès',
        icon: ShieldCheck,
    },
    {
        value: 'allow',
        label: 'Toujours autoriser',
        hint: props.roleGranted ? 'Inutile : le rôle l’accorde déjà' : 'Accès même si le rôle ne l’accorde pas',
        icon: ShieldPlus,
    },
    {
        value: 'deny',
        label: 'Toujours interdire',
        hint: props.roleGranted ? 'Retire l’accès que le rôle accorde' : 'Verrouillé, même si le rôle l’accorde un jour',
        icon: ShieldX,
    },
]);

const model = computed({
    get: () => props.effect || 'inherit',
    set: (value) => emit('change', value === 'inherit' ? '' : value),
});

const boxClass = computed(() => ({
    deny: 'border-destructive bg-destructive text-destructive-foreground shadow-sm',
    allow: 'border-emerald-600 bg-emerald-600 text-white shadow-sm',
    role: 'border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    none: 'border-input bg-card text-transparent',
}[state.value.tone]));

const title = computed(() => [
    `${label.value} · ${props.permission.name}`,
    state.value.text,
    props.sourceLabel,
    props.changed ? 'Modifié, pas encore enregistré' : '',
    props.sensitive ? 'Permission sensible' : '',
].filter(Boolean).join('\n'));

const ariaLabel = computed(() => `${[props.text || label.value, props.context].filter(Boolean).join(' — ')} : ${state.value.text}`);
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child :disabled="disabled">
            <button
                v-if="presentation === 'box'"
                type="button"
                :aria-label="ariaLabel"
                :title="title"
                :disabled="disabled"
                :class="cn(
                    'relative grid h-7 w-7 place-items-center rounded-md border transition-[background-color,border-color,box-shadow,opacity] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-card disabled:cursor-not-allowed data-[state=open]:ring-2 data-[state=open]:ring-primary',
                    boxClass,
                    ! disabled && state.tone === 'none' ? 'hover:border-primary/60 hover:bg-primary/5' : '',
                    changed ? 'ring-2 ring-amber-400 ring-offset-1 ring-offset-card' : '',
                    dimmed ? 'opacity-30' : '',
                )"
            >
                <X v-if="effect === 'deny'" class="h-4 w-4" :stroke-width="3" aria-hidden="true" />
                <Check v-else class="h-4 w-4" :stroke-width="3" aria-hidden="true" />
                <TriangleAlert v-if="sensitive" class="absolute -bottom-1.5 -end-1.5 h-3 w-3 rounded-full bg-card p-px text-amber-500" aria-hidden="true" />
            </button>

            <button
                v-else
                type="button"
                :aria-label="ariaLabel"
                :title="title"
                :disabled="disabled"
                :class="cn(
                    'inline-flex max-w-full items-center gap-2 rounded-2xl border py-1 pe-3 ps-1.5 text-start text-xs font-semibold leading-4 transition-[background-color,border-color,box-shadow,opacity] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed data-[state=open]:ring-2 data-[state=open]:ring-primary',
                    effect === 'deny' ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200'
                        : effective ? 'border-emerald-200 bg-emerald-50/70 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/25 dark:text-emerald-100'
                            : 'border-border bg-card text-muted-foreground hover:text-foreground',
                    changed ? 'ring-2 ring-amber-400 ring-offset-1 ring-offset-card' : '',
                    dimmed ? 'opacity-30' : '',
                )"
            >
                <span :class="cn('grid h-4 w-4 shrink-0 place-items-center rounded border', boxClass)" aria-hidden="true">
                    <X v-if="effect === 'deny'" class="h-3 w-3" :stroke-width="3" />
                    <Check v-else class="h-3 w-3" :stroke-width="3" />
                </span>
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ text || label }}</span>
                <span v-if="effect" class="shrink-0 rounded-full bg-card/70 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide">Exception</span>
                <TriangleAlert v-if="sensitive" class="h-3 w-3 shrink-0 text-amber-500" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                align="center"
                :side-offset="6"
                class="z-[1500] w-[min(20rem,calc(100vw-2rem))] rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-xl focus:outline-none data-[state=open]:animate-[rivo-popover-in_90ms_ease-out]"
            >
                <div class="px-2.5 pb-2 pt-2">
                    <p class="text-sm font-semibold leading-5 text-foreground">{{ label }}</p>
                    <p class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground" :title="permission.name">{{ permission.name }}</p>
                    <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span>Socle du rôle :</span>
                        <strong :class="roleGranted ? 'text-emerald-700 dark:text-emerald-300' : 'text-foreground'">{{ roleGranted ? 'accorde ce droit' : 'ne l’accorde pas' }}</strong>
                        <span v-if="sensitive" class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300"><TriangleAlert class="h-3 w-3" />Sensible</span>
                    </p>
                </div>
                <DropdownMenuSeparator class="-mx-1 my-1 h-px bg-border" />
                <DropdownMenuRadioGroup v-model="model">
                    <DropdownMenuRadioItem
                        v-for="choice in choices"
                        :key="choice.value || 'inherit'"
                        :value="choice.value || 'inherit'"
                        class="relative flex cursor-default select-none items-start gap-2.5 rounded-lg py-2 pe-8 ps-2.5 text-sm outline-none transition-colors data-[highlighted]:bg-accent data-[state=checked]:bg-primary/5"
                    >
                        <component :is="choice.icon" :class="cn('mt-0.5 h-4 w-4 shrink-0', choice.value === 'allow' ? 'text-emerald-600' : choice.value === 'deny' ? 'text-destructive' : 'text-muted-foreground')" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block font-medium leading-5 text-foreground">{{ choice.label }}</span>
                            <span class="mt-0.5 block text-xs leading-4 text-muted-foreground">{{ choice.hint }}</span>
                        </span>
                        <DropdownMenuItemIndicator class="absolute end-2.5 top-2.5 text-primary">
                            <Check class="h-4 w-4" />
                        </DropdownMenuItemIndicator>
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <p v-if="sourceLabel" class="px-2.5 pb-2 pt-1 text-[11px] text-primary">Provenance : {{ sourceLabel }}</p>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
