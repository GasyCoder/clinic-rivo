<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    ChevronDown,
    FileText,
    Pill,
    ShieldCheck,
    Wallet,
} from 'lucide-vue-next';
import { menuMatchDepth } from '@/utilities/menuActivation';
import { lucideIcon } from '@/lib/icons';

/**
 * Navigation propre au portail central.
 *
 * Les groupes ne changent ni route ni autorisation : Menu.vue filtre d'abord
 * chaque entrée par la permission dynamique, puis ce composant ne fait que
 * présenter les survivantes. Un seul bloc est ouvert à la fois pour que les
 * vingt actions du portail restent parcourables sans une colonne interminable.
 */
const props = defineProps({
    sections: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['navigate']);
const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
const hasModuleQuery = computed(() => /(?:^|[?&])module=/.test(page.url));

const sectionIcons = {
    Établissements: Building2,
    Finances: Wallet,
    Référentiels: FileText,
    'Pharmacie & stocks': Pill,
    Organisation: Briefcase,
    'Accès & système': ShieldCheck,
};

const overview = computed(() => props.sections.find((section) => section.key === 'Vue centrale')?.items[0] ?? null);
const groups = computed(() => props.sections.filter((section) => section.key !== 'Vue centrale'));
const items = computed(() => props.sections.flatMap((section) => section.items));

const deepestMatch = computed(() => items.value.reduce(
    (best, item) => Math.max(best, menuMatchDepth(item, currentPath.value)),
    -1,
));

const isActive = (item) => {
    const depth = menuMatchDepth(item, currentPath.value);

    return depth !== -1 && depth === deepestMatch.value;
};

const activeSectionKey = computed(() => groups.value.find(
    (section) => section.items.some(isActive),
)?.key ?? null);

// Le chemin courant décide du bloc ouvert à chaque navigation. Entre deux
// navigations, l'utilisateur peut aussi tout replier ou explorer un autre bloc.
const openSectionKey = ref(null);
watch(activeSectionKey, (key) => { openSectionKey.value = key; }, { immediate: true });

const toggleSection = (key) => {
    openSectionKey.value = openSectionKey.value === key ? null : key;
};

const itemKey = (item) => item.key ?? item.link ?? item.text;
const sectionId = (section) => `admin-menu-${section.key.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

const activeSiteKey = computed(() => groups.value
    .flatMap((section) => section.items)
    .find((item) => item.children && isActive(item))
    ?.text ?? null);
const openSiteKey = ref(null);
watch(activeSiteKey, (key) => { openSiteKey.value = key; }, { immediate: true });

const toggleSite = (item) => {
    openSiteKey.value = openSiteKey.value === item.text ? null : item.text;
};

const isChildActive = (item, child) => {
    if (!isActive(item)) return false;

    const childPath = child.link.split('?')[0];

    return page.url === child.link
        || (child.code === 'OVERVIEW' && currentPath.value === childPath && !hasModuleQuery.value)
        || (['HR', 'PHARMACY'].includes(child.code)
            && (currentPath.value === childPath || currentPath.value.startsWith(`${childPath}/`)));
};
</script>

<template>
    <nav aria-label="Navigation Super Administration" :class="[compact ? 'px-2' : 'px-3', 'pb-6']">
        <ul class="space-y-1.5">
            <li v-if="overview" class="pb-1">
                <Link
                    :href="overview.link"
                    :aria-current="isActive(overview) ? 'page' : undefined"
                    :class="[
                        'group relative flex items-center gap-2 rounded-lg border px-2.5 py-2.5 transition-colors',
                        compact ? 'justify-center' : '',
                        isActive(overview)
                            ? 'border-primary/30 bg-primary/15 text-primary shadow-sm'
                            : 'border-transparent text-muted-foreground hover:border-border hover:bg-accent/60 hover:text-foreground',
                    ]"
                    :title="compact ? overview.text : undefined"
                    @click="emit('navigate')"
                >
                    <span v-if="isActive(overview)" class="absolute inset-y-2 start-0 w-1 rounded-e-full bg-primary" aria-hidden="true" />
                    <span class="grid size-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                        <component :is="overview.icon" class="size-4" />
                    </span>
                    <span v-if="!compact" class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] font-bold">{{ overview.text }}</span>
                        <span class="block truncate text-[10px] font-medium text-muted-foreground">Pilotage central</span>
                    </span>
                    <span v-if="isActive(overview) && !compact" class="size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                </Link>
            </li>

            <li
                v-for="section in groups"
                :key="section.key"
                :class="[
                    'overflow-hidden rounded-lg border transition-colors',
                    activeSectionKey === section.key
                        ? 'border-primary/25 bg-primary/[0.04]'
                        : 'border-border/70 bg-card/40',
                ]"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-2.5 px-2.5 py-2.5 text-start text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    :class="compact ? 'justify-center' : ''"
                    :aria-expanded="!compact && openSectionKey === section.key"
                    :aria-controls="sectionId(section)"
                    :title="compact ? section.label : undefined"
                    @click="toggleSection(section.key)"
                >
                    <span class="grid size-8 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                        <component :is="sectionIcons[section.key] ?? FileText" class="size-4" />
                    </span>
                    <span v-if="!compact" class="min-w-0 flex-1 truncate text-[11px] font-bold uppercase tracking-[0.12em]">{{ section.label }}</span>
                    <span v-if="!compact" class="rounded-full bg-muted px-1.5 py-0.5 text-[9px] font-bold tabular-nums text-muted-foreground">{{ section.items.length }}</span>
                    <ChevronDown v-if="!compact" :class="['size-3.5 shrink-0 transition-transform', openSectionKey === section.key ? 'rotate-180 text-primary' : '']" />
                </button>

                <ul
                    v-show="!compact && openSectionKey === section.key"
                    :id="sectionId(section)"
                    class="space-y-0.5 border-t border-border/70 px-1.5 py-1.5"
                >
                    <li v-for="item in section.items" :key="itemKey(item)">
                        <template v-if="item.children">
                            <button
                                type="button"
                                :class="[
                                    'flex w-full items-center gap-2 rounded-md px-2 py-2 text-start transition-colors',
                                    isActive(item) ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                                ]"
                                :aria-expanded="openSiteKey === item.text"
                                @click="toggleSite(item)"
                            >
                                <span class="grid size-7 shrink-0 place-items-center"><component :is="item.icon" class="size-4" /></span>
                                <span class="min-w-0 flex-1 truncate text-xs font-semibold">{{ item.text }}</span>
                                <span v-if="item.integrationStatus" :class="['rounded border px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide', item.integrationStatus === 'CONFIGURED' ? 'border-emerald-500/30 text-emerald-500' : 'border-border text-muted-foreground']">API</span>
                                <ChevronDown :class="['size-3.5 shrink-0 transition-transform', openSiteKey === item.text ? 'rotate-180' : '']" />
                            </button>

                            <ul v-show="openSiteKey === item.text" class="ms-[22px] border-s border-border pb-1 ps-3 pe-1">
                                <li v-for="child in item.children" :key="child.code">
                                    <Link
                                        :href="child.link"
                                        :aria-current="isChildActive(item, child) ? 'page' : undefined"
                                        :class="[
                                            'flex items-center gap-2 rounded px-2.5 py-1.5 text-[11px] transition-colors',
                                            isChildActive(item, child) ? 'bg-primary/10 font-bold text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                        ]"
                                        @click="emit('navigate')"
                                    >
                                        <component :is="lucideIcon(child.icon)" v-if="child.icon" class="size-3.5 shrink-0" />
                                        <span class="min-w-0 truncate">{{ child.label }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </template>

                        <Link
                            v-else
                            :href="item.link"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            :class="[
                                'group relative flex items-center gap-2 rounded-md px-2 py-2 transition-colors',
                                isActive(item) ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                            ]"
                            @click="emit('navigate')"
                        >
                            <span v-if="isActive(item)" class="absolute inset-y-1 start-0 w-0.5 rounded-e-full bg-primary" aria-hidden="true" />
                            <span class="grid size-7 shrink-0 place-items-center"><component :is="item.icon" class="size-4" /></span>
                            <span class="min-w-0 flex-1 truncate text-xs font-semibold">{{ item.text }}</span>
                            <span v-if="isActive(item)" class="size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>
</template>
