<script setup>
import { computed, ref } from 'vue';
import { Loader2, UserCheck, UsersRound } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { useToastStore } from '@/stores/toast';

/**
 * ADR-199 — personne au site ne peut encore recevoir les accès : le Super Admin
 * désigne ici qui les remettra.
 *
 * Les mots de passe ne repassent jamais par le portail (ADR-197) : c'est une
 * personne du site — le RH, en général — qui les affiche, les imprime et les
 * remet. Désigner un compte lui donne le droit `staff_access.receive`, en
 * exception individuelle auditée ; le serveur revérifie tout.
 */
const props = defineProps({
    site: { type: String, required: true },
    siteName: { type: String, default: '' },
    /** Les comptes actifs du site : `{ uuid, name, role, receives }`. */
    accounts: { type: Array, default: () => [] },
    canDesignate: { type: Boolean, default: false },
});
const emit = defineEmits(['designated']);

const toast = useToastStore();
const chosen = ref('');
const saving = ref(false);
const error = ref('');

const options = computed(() => [
    { value: '', label: 'Choisir la personne du site…' },
    ...props.accounts.map((account) => ({ value: account.uuid, label: [account.name, account.role].filter(Boolean).join(' · ') })),
]);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const designate = async () => {
    if (!chosen.value) return;
    saving.value = true;
    error.value = '';
    try {
        const response = await fetch(`/super-admin/staff-access/${props.site}/receivers`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ user_uuid: chosen.value }),
        });
        const json = await response.json().catch(() => ({}));
        if (!response.ok) {
            error.value = Object.values(json.errors ?? {}).flat()[0] ?? json.message ?? 'Le site a refusé.';
            return;
        }
        toast.success(json.message ?? 'Compte désigné.');
        emit('designated', { receivers: json.receivers ?? 1, accounts: json.accounts ?? props.accounts });
        chosen.value = '';
    } catch {
        error.value = 'Le portail n’a pas répondu. Réessayez.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <div class="rounded-lg border border-amber-200 bg-amber-50/70 p-3 dark:border-amber-900 dark:bg-amber-950/25">
        <p class="flex items-start gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200">
            <UsersRound class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Personne à {{ siteName || site }} ne peut encore remettre ces accès
        </p>
        <p class="mt-1 ps-6 text-xs leading-5 text-amber-900/80 dark:text-amber-200/80">
            Les mots de passe sont remis en main propre par une personne du site — le RH, en général — et ne repassent pas par le portail.
            Choisissez qui les remettra : elle en recevra le droit, et vous pourrez envoyer.
        </p>

        <div v-if="canDesignate && accounts.length" class="mt-3 flex flex-col gap-2 ps-6 sm:flex-row sm:items-center">
            <Select v-model="chosen" :options="options" class="w-full sm:max-w-sm" :aria-label="`Personne qui remettra les accès à ${siteName || site}`" />
            <Button type="button" size="sm" :disabled="!chosen || saving" @click="designate">
                <Loader2 v-if="saving" class="h-4 w-4 animate-spin" aria-hidden="true" />
                <UserCheck v-else class="h-4 w-4" aria-hidden="true" />Désigner
            </Button>
        </div>
        <p v-else-if="canDesignate" class="mt-2 ps-6 text-xs text-amber-900/80 dark:text-amber-200/80">Aucun compte actif sur ce site : créez d’abord le compte de la personne qui remettra les accès (onglet « Comptes »).</p>
        <p v-else class="mt-2 ps-6 text-xs text-amber-900/80 dark:text-amber-200/80">Désigner cette personne demande le droit « permissions.assign ».</p>
        <p v-if="error" class="mt-2 ps-6 text-xs font-medium text-red-700 dark:text-red-400" role="alert">{{ error }}</p>
    </div>
</template>
