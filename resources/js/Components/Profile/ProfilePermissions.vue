<script setup>
import { computed, ref } from 'vue';
import { Search, ShieldOff } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { PERMISSION_MODULES, permissionCategoryModule } from '@/utilities/permissionCategories';

/**
 * Ce que le compte peut réellement faire (ADR-184) : les droits effectifs —
 * socle du rôle et exceptions individuelles compris —, rangés par les mêmes
 * modules que « Rôles & permissions » (ADR-178). En lecture seule.
 */
const props = defineProps({
    permissions: { type: Array, default: () => [] },
});

const query = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const modules = computed(() => {
    const words = normalize(query.value).split(/\s+/).filter(Boolean);
    const matches = (permission) => words.every((word) => normalize(`${permission.label} ${permission.name}`).includes(word));

    return PERMISSION_MODULES
        .map((module) => ({
            ...module,
            permissions: props.permissions
                .filter((permission) => permissionCategoryModule(permission.name.split('.')[0]) === module.key)
                .filter(matches)
                .sort((a, b) => a.label.localeCompare(b.label, 'fr')),
        }))
        .filter((module) => module.permissions.length > 0);
});
</script>

<template>
    <div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted-foreground">
                <strong class="text-foreground">{{ permissions.length }}</strong> droit{{ permissions.length > 1 ? 's' : '' }}, socle du rôle et exceptions compris.
            </p>
            <IconInput v-model="query" :icon="Search" class="sm:w-64" placeholder="Chercher un droit" aria-label="Chercher un droit" />
        </div>

        <div v-if="modules.length" class="mt-4 space-y-3">
            <section v-for="module in modules" :key="module.key" class="rounded-xl border border-border">
                <header class="flex items-center gap-2.5 border-b border-border px-4 py-2.5">
                    <component :is="module.icon" class="h-4 w-4 text-primary" aria-hidden="true" />
                    <h3 class="text-sm font-semibold text-foreground">{{ module.label }}</h3>
                    <Badge variant="outline" class="ms-auto">{{ module.permissions.length }}</Badge>
                </header>
                <ul class="flex flex-wrap gap-1.5 px-4 py-3">
                    <li v-for="permission in module.permissions" :key="permission.name" class="rounded-md bg-muted px-2 py-1 text-xs text-foreground" :title="permission.name">
                        {{ permission.label }}
                    </li>
                </ul>
            </section>
        </div>
        <p v-else class="mt-4 flex items-center gap-2 rounded-xl border border-dashed border-border px-4 py-6 text-sm text-muted-foreground">
            <ShieldOff class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ permissions.length ? 'Aucun droit ne correspond à la recherche.' : 'Ce compte n’a aucun droit.' }}
        </p>
    </div>
</template>
