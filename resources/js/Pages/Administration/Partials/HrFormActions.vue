<script setup>
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineProps({
    cancelHref: String,
    submitLabel: String,
    processing: Boolean,
    submitIcon: { type: String, default: 'check' },
});
</script>

<template>
    <footer class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-950/95 sm:flex-row sm:items-center sm:justify-between">
        <Button :as="Link" :href="cancelHref" size="rg" variant="white-outline">Annuler</Button>
        <div class="flex items-center justify-end gap-3"><slot /><Button size="rg" type="submit" :disabled="processing"><Icon :name="submitIcon" /><span class="ms-2">{{ processing ? 'Enregistrement…' : submitLabel }}</span></Button></div>
    </footer>
</template>
