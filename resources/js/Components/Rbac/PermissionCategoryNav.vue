<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { ChevronDown, ChevronsDownUp, ChevronsUpDown, Search, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { normalizePermissionText } from '@/utilities/permissionWorkspace';
import { cn } from '@/lib/cn';

/**
 * Le rail des catégories de permissions.
 *
 * Une quarantaine de catégories réparties sur sept domaines. Ce qui la rend
 * parcourable, dans cet ordre :
 *
 *  - une hauteur qui suit l'écran : le parent lui donne la place disponible,
 *    la liste défile dedans, et la dernière ligne n'est jamais masquée par la
 *    barre d'enregistrement ;
 *  - des domaines repliables, avec leur nombre de catégories : sept titres se
 *    survolent, quarante lignes se lisent une à une ;
 *  - des lignes assez hautes pour être visées, un compteur en colonne ;
 *  - le clavier : flèches, Début et Fin passent d'une catégorie à l'autre.
 *
 * Le même rail sert la configuration d'un compte et le socle d'un rôle : la
 * navigation ne doit pas changer d'allure selon ce qu'on règle.
 */
const props = defineProps({
    /**
     * `[{ key, label, categories: [{ key, label, count, marked, hidden, title }] }]`
     * — le parent décide ce que compte `count` (droits visibles, accordés
     * sur total…) et ce que signale `marked` ; le rail ne fait que l'afficher.
     */
    groups: { type: Array, default: () => [] },
    modelValue: { type: String, default: '' },
    markedTitle: { type: String, default: '' },
    class: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

/**
 * Quarante catégories dans une colonne : sans filtre, on les parcourait à
 * l'œil jusqu'à trouver la bonne. Le champ ne cherche que dans ce rail — le
 * code d'une catégorie compte autant que son libellé, parce qu'on connaît
 * parfois l'un sans l'autre.
 */
const filter = ref('');

const matches = (category) => {
    const term = normalizePermissionText(filter.value).trim();

    if (term === '') return true;

    return normalizePermissionText(`${category.label} ${category.key}`).includes(term);
};

const filtering = computed(() => filter.value.trim() !== '');

const visibleGroups = computed(() => props.groups
    .map((group) => ({
        ...group,
        categories: group.categories.filter((category) => ! category.hidden && matches(category)),
    }))
    .filter((group) => group.categories.length));

const total = computed(() => props.groups.reduce(
    (count, group) => count + group.categories.filter((category) => ! category.hidden).length,
    0,
));

const shown = computed(() => visibleGroups.value.reduce((count, group) => count + group.categories.length, 0));

/**
 * Domaines repliés, par clé. Une recherche déplie tout : c'est précisément
 * quand on ne sait pas où vit une catégorie qu'on la tape, et un résultat
 * caché dans un domaine fermé n'en serait pas un.
 */
const collapsed = ref({});

const isOpen = (group) => filtering.value || ! collapsed.value[group.key];

const toggle = (group) => {
    collapsed.value = { ...collapsed.value, [group.key]: isOpen(group) };
};

const setAll = (closed) => {
    collapsed.value = Object.fromEntries(props.groups.map((group) => [group.key, closed]));
};

const allClosed = computed(() => visibleGroups.value.length > 0
    && visibleGroups.value.every((group) => collapsed.value[group.key]));

const list = ref(null);

/**
 * La catégorie ouverte reste visible : son domaine se déplie et la ligne est
 * amenée dans la fenêtre — sinon un changement venu d'ailleurs (recherche,
 * clavier) sélectionnait une ligne hors de vue.
 */
watch(() => props.modelValue, async (key) => {
    const owner = props.groups.find((group) => group.categories.some((category) => category.key === key));

    if (owner && collapsed.value[owner.key]) collapsed.value = { ...collapsed.value, [owner.key]: false };

    await nextTick();
    list.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
});

const rows = () => Array.from(list.value?.querySelectorAll('[data-category]') ?? []);

/**
 * Les flèches passent d'une ligne à l'autre sans quitter le rail. Entrée et
 * Espace ouvrent la catégorie sans code : ce sont de vrais boutons.
 */
const onKeydown = (event) => {
    if (! ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    const buttons = rows();

    if (buttons.length === 0) return;

    const at = buttons.indexOf(document.activeElement);
    const next = {
        ArrowDown: Math.min(buttons.length - 1, at + 1),
        ArrowUp: Math.max(0, at === -1 ? 0 : at - 1),
        Home: 0,
        End: buttons.length - 1,
    }[event.key];

    event.preventDefault();
    buttons[next]?.focus();
    buttons[next]?.scrollIntoView({ block: 'nearest' });
};
</script>

<template>
    <div :class="cn('flex min-h-0 flex-col overflow-hidden rounded-lg border border-border bg-card', props.class)">
        <div class="shrink-0 space-y-2 border-b border-border p-3">
            <div class="relative">
                <IconInput
                    v-model="filter"
                    :icon="Search"
                    type="search"
                    class="h-10 pe-9 text-sm"
                    placeholder="Filtrer les catégories…"
                    autocomplete="off"
                    aria-label="Filtrer les catégories"
                />
                <Button
                    v-if="filtering"
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    class="absolute end-1.5 top-1/2 z-20 -translate-y-1/2"
                    aria-label="Effacer le filtre"
                    @click="filter = ''"
                >
                    <X class="h-3.5 w-3.5" />
                </Button>
            </div>

            <div class="flex items-center justify-between gap-2">
                <p class="text-xs tabular-nums text-muted-foreground">
                    <template v-if="filtering">{{ shown }} sur {{ total }} catégories</template>
                    <template v-else>{{ total }} catégories</template>
                </p>
                <Button
                    v-if="! filtering && visibleGroups.length > 1"
                    type="button"
                    variant="ghost"
                    size="xs"
                    class="text-muted-foreground"
                    @click="setAll(! allClosed)"
                >
                    <component :is="allClosed ? ChevronsUpDown : ChevronsDownUp" class="h-3.5 w-3.5" />
                    {{ allClosed ? 'Tout déplier' : 'Tout replier' }}
                </Button>
            </div>
        </div>

        <div ref="list" class="min-h-[12rem] flex-1 overflow-y-auto overscroll-contain" @keydown="onKeydown">
            <template v-for="group in visibleGroups" :key="group.key">
                <button
                    type="button"
                    class="sticky top-0 z-10 flex w-full items-center gap-2 border-b border-t border-border bg-muted px-3 py-2 text-start text-[11px] font-bold uppercase tracking-wider text-muted-foreground transition-colors first:border-t-0 hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    :aria-expanded="isOpen(group)"
                    @click="toggle(group)"
                >
                    <ChevronDown :class="cn('h-3.5 w-3.5 shrink-0 transition-transform', ! isOpen(group) && '-rotate-90')" />
                    <span class="min-w-0 flex-1 truncate">{{ group.label }}</span>
                    <span
                        v-if="! isOpen(group) && group.categories.some((category) => category.marked)"
                        class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                        :title="markedTitle"
                    />
                    <span class="shrink-0 font-semibold tabular-nums">{{ group.categories.length }}</span>
                </button>

                <template v-if="isOpen(group)">
                    <button
                        v-for="category in group.categories"
                        :key="category.key"
                        type="button"
                        data-category
                        :title="category.title"
                        :class="cn(
                            'relative flex w-full items-center gap-2.5 border-b border-border/50 px-3 py-2.5 text-start text-sm leading-5 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                            modelValue === category.key
                                ? 'bg-primary/10 font-semibold text-foreground'
                                : 'text-foreground/80 hover:bg-accent hover:text-foreground',
                        )"
                        :aria-current="modelValue === category.key ? 'page' : undefined"
                        @click="emit('update:modelValue', category.key)"
                    >
                        <!-- Même repère d'état actif que le menu latéral de
                             l'application : un rail, pas une couleur de texte
                             seule, qui se voit du coin de l'œil en défilant. -->
                        <span v-if="modelValue === category.key" class="absolute inset-y-1 start-0 w-1 rounded-e-full bg-primary" />
                        <span class="min-w-0 flex-1 truncate">{{ category.label }}</span>
                        <span v-if="category.marked" class="h-2 w-2 shrink-0 rounded-full bg-primary" :title="markedTitle" />
                        <Badge
                            :variant="modelValue === category.key ? 'default' : 'outline'"
                            class="shrink-0 px-2 py-0.5 text-[11px] tabular-nums"
                        >{{ category.count }}</Badge>
                    </button>
                </template>
            </template>

            <p v-if="visibleGroups.length === 0" class="px-3 py-8 text-center text-sm text-muted-foreground">
                Aucune catégorie ne correspond.
                <Button v-if="filtering" type="button" variant="link" size="sm" class="mt-1" @click="filter = ''">
                    Effacer le filtre
                </Button>
            </p>
        </div>

        <div v-if="$slots.footer" class="shrink-0 border-t border-border bg-muted/40 p-2">
            <slot name="footer" />
        </div>
    </div>
</template>
