<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Loader2, Save } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ThemeModeSwitcher from '@/Components/Layout/ThemeModeSwitcher.vue';
import OptionPills from '@/Components/Settings/OptionPills.vue';
import { applyAppearance, CONTRASTS, FONT_SIZES, MOTIONS } from '@/utilities/appearance';

/**
 * ADR-191 — ce que chacun règle pour lui-même : taille du texte, animations,
 * contraste. « Selon le site » reprend la valeur choisie par l'administration.
 * Le choix s'essaie aussitôt sur la page ; il ne dure qu'une fois enregistré
 * (quitter la page sans enregistrer rétablit l'apparence enregistrée).
 */
const page = usePage();
const appearance = computed(() => page.props.appearance ?? { site: {}, user: {}, effective: {} });

const label = (list, value) => list.find((option) => option.value === value)?.label ?? value;

const form = useForm({
    font_size: appearance.value.user?.font_size ?? null,
    motion: appearance.value.user?.motion ?? null,
    contrast: appearance.value.user?.contrast ?? null,
});

const fontOptions = computed(() => [
    { value: null, label: `Selon le site (${appearance.value.site?.font_size ?? 16} px)` },
    ...FONT_SIZES.map((size) => ({ value: size, label: `${size} px` })),
]);
const motionOptions = computed(() => [
    { value: null, label: `Selon le site (${label(MOTIONS, appearance.value.site?.motion)})` },
    ...MOTIONS,
]);
const contrastOptions = computed(() => [
    { value: null, label: `Selon le site (${label(CONTRASTS, appearance.value.site?.contrast)})` },
    ...CONTRASTS,
]);

const preview = computed(() => ({
    ...appearance.value.effective,
    font_size: form.font_size ?? appearance.value.site?.font_size,
    motion: form.motion ?? appearance.value.site?.motion,
    contrast: form.contrast ?? appearance.value.site?.contrast,
}));

// L'essai s'applique aussitôt ; sans enregistrement, il est retiré en quittant la page.
watch(preview, (value) => applyAppearance(value), { deep: true });
onBeforeUnmount(() => applyAppearance(page.props.appearance?.effective));

const submit = () => form.put('/profil/apparence', { preserveScroll: true });
</script>

<template>
    <form class="flex max-w-2xl flex-col gap-6" @submit.prevent="submit">
        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Mode d’affichage</h3>
            <p class="text-xs text-muted-foreground">Clair, sombre ou selon votre appareil. Gardé sur ce poste.</p>
            <ThemeModeSwitcher />
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Taille du texte</h3>
            <p class="text-xs text-muted-foreground">Agrandit ou réduit toute l’interface, comme un zoom.</p>
            <OptionPills v-model="form.font_size" :options="fontOptions" label="Taille du texte" size="sm" />
            <p v-if="form.errors.font_size" class="text-xs font-medium text-destructive">{{ form.errors.font_size }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Animations</h3>
            <p class="text-xs text-muted-foreground">Réduites : les fenêtres et menus s’ouvrent sans glissement ni fondu.</p>
            <OptionPills v-model="form.motion" :options="motionOptions" label="Animations" size="sm" />
            <p v-if="form.errors.motion" class="text-xs font-medium text-destructive">{{ form.errors.motion }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Contraste</h3>
            <p class="text-xs text-muted-foreground">Bordures et texte secondaire plus marqués, pour mieux lire.</p>
            <OptionPills v-model="form.contrast" :options="contrastOptions" label="Contraste" size="sm" />
            <p v-if="form.errors.contrast" class="text-xs font-medium text-destructive">{{ form.errors.contrast }}</p>
        </section>

        <div class="flex items-center gap-3 border-t border-border pt-4">
            <Button type="submit" :disabled="form.processing || ! form.isDirty">
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><Save v-else class="h-4 w-4" />Enregistrer l’apparence
            </Button>
            <p class="text-xs text-muted-foreground">Gardée sur votre compte : elle vous suit sur chaque poste.</p>
        </div>
    </form>
</template>
