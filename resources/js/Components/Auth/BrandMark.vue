<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { monogramOf } from '@/lib/brand';

const page = usePage();
const site = computed(() => page.props.site);
const logoUrl = computed(() => site.value.documents?.logo_url || null);

// Les mêmes initiales que la barre latérale : deux repli différents pour la
// même enseigne se liraient comme deux applications.
const monogram = computed(() => monogramOf(site.value.brand));
</script>

<template>
    <div class="mb-8 flex min-h-16 items-center">
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="`Logo ${site.brand}`"
            class="h-auto w-full max-w-[270px] object-contain object-left"
        />
        <div v-else class="flex items-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-primary font-heading text-sm font-bold text-primary-foreground">
                {{ monogram }}
            </span>
            <div class="font-heading text-sm font-bold text-foreground">{{ site.brand }}</div>
        </div>
    </div>
</template>
