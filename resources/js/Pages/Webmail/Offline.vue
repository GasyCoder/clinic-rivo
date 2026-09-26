<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { CloudOff, LogOut } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';

/**
 * ADR-195 — le serveur de messagerie ne répond pas. Rien n'est perdu : les
 * messages restent chez l'hébergeur. On réessaie, ou on ferme la boîte.
 */
defineOptions({ layout: AppLayout });

defineProps({
    message: { type: String, required: true },
    address: { type: String, default: '' },
});

const retrying = ref(false);
const retry = () => {
    retrying.value = true;
    router.reload({ onFinish: () => { retrying.value = false; } });
};
</script>

<template>
    <Head title="Messagerie indisponible" />

    <div class="mx-auto flex max-w-lg flex-col items-center gap-4 py-16 text-center">
        <span class="grid h-16 w-16 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300"><CloudOff class="h-7 w-7" aria-hidden="true" /></span>
        <h1 class="text-xl font-bold text-foreground">Messagerie momentanément indisponible</h1>
        <p class="text-sm text-muted-foreground">{{ message }}</p>
        <p v-if="address" class="text-xs text-muted-foreground">Boîte : {{ address }} — vos messages restent chez l’hébergeur.</p>
        <div class="flex flex-wrap justify-center gap-2">
            <Button type="button" :aria-busy="retrying" :disabled="retrying" @click="retry"><RefreshIcon :spinning="retrying" class="h-4 w-4" /> Réessayer</Button>
            <Button type="button" variant="outline" @click="router.post('/messagerie/deconnexion')"><LogOut class="h-4 w-4" aria-hidden="true" /> Fermer ma boîte</Button>
        </div>
    </div>
</template>
