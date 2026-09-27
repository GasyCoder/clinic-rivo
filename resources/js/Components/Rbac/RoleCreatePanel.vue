<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, FilePlus2, Info, Loader2, ShieldPlus } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * Créer un rôle, sur place (ADR-100, ADR-178).
 *
 * Plus de fenêtre : la liste des rôles reste visible à gauche pendant qu'on
 * nomme le nouveau, et il s'ouvre dès sa création dans la grille — c'est là
 * qu'on règle son socle en détail.
 *
 * Le code se propose tout seul à partir du libellé (« Kinésithérapeute » →
 * « KINESITHERAPEUTE ») tant qu'on ne l'a pas touché : c'est l'identité du
 * rôle, il ne change plus ensuite, et le serveur le renormalise de toute
 * façon (`RoleCode::normalize`). Le socle de départ est la liste réellement
 * envoyée : reprendre celui d'un rôle le copie au moment du clic, sans lien
 * entre les deux ensuite.
 */
const props = defineProps({
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    roles: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
});

const emit = defineEmits(['created', 'cancel']);

const form = useForm({ site_code: props.siteCode, code: '', name: '', permission_ids: [] });

const codeTouched = ref(false);
const start = ref('empty');
const copyFrom = ref('');

/** Majuscules, sans accent ni espace : lettres, chiffres et « _ » (ADR-100). */
const suggestCode = (name) => String(name ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^[^A-Z]+/, '')
    .replace(/_+$/, '')
    .slice(0, 40);

watch(() => form.name, (name) => {
    if (! codeTouched.value) form.code = suggestCode(name);
});

const onCodeInput = (value) => {
    codeTouched.value = true;
    form.code = String(value ?? '').toUpperCase();
};

const normalizedCode = computed(() => form.code.trim().toUpperCase());
const codeValid = computed(() => /^[A-Z][A-Z0-9_]{1,39}$/.test(normalizedCode.value));
const codeTaken = computed(() => props.roles.find((role) => role.code === normalizedCode.value) ?? null);

const sourceRoles = computed(() => props.roles.filter((role) => ! role.protected && ! role.archived));

const copyOptions = computed(() => sourceRoles.value.map((role) => ({
    value: role.code,
    label: `${role.name} — ${role.permissions.length} droit${role.permissions.length > 1 ? 's' : ''}`,
})));

watch([start, copyFrom], ([mode, code]) => {
    const source = mode === 'copy' ? props.roles.find((role) => role.code === code) : null;

    form.permission_ids = source
        ? props.catalog.filter((permission) => source.permissions.includes(permission.name)).map((permission) => permission.id)
        : [];
});

const chooseCopy = () => {
    start.value = 'copy';
    if (! copyFrom.value) copyFrom.value = sourceRoles.value[0]?.code ?? '';
};

const canSubmit = computed(() => form.name.trim() !== '' && codeValid.value && ! codeTaken.value && ! form.processing);

const submit = () => {
    if (! canSubmit.value) return;

    form
        .transform((data) => ({ ...data, code: normalizedCode.value, name: data.name.trim(), site_code: props.siteCode }))
        .post('/super-admin/workspaces/roles', {
            preserveScroll: true,
            onSuccess: () => emit('created', normalizedCode.value),
        });
};
</script>

<template>
    <Card class="overflow-hidden">
        <header class="flex items-start gap-4 border-b border-border px-5 py-4">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
                <ShieldPlus class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="font-heading text-lg font-bold text-foreground">Nouveau rôle</h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Créé sur <strong class="text-foreground">{{ siteName }}</strong>, via l’API du site. Vous réglerez ensuite son socle dans la grille.
                </p>
            </div>
            <Button type="button" variant="ghost" size="sm" :disabled="form.processing" @click="emit('cancel')">
                <ArrowLeft class="h-4 w-4" />Retour
            </Button>
        </header>

        <form class="space-y-5 px-5 py-5" @submit.prevent="submit">
            <FormError v-if="form.errors.site_code">{{ form.errors.site_code }}</FormError>

            <div class="grid gap-4 md:grid-cols-2">
                <FormField label="Libellé" :error="form.errors.name" required>
                    <Input v-model="form.name" placeholder="Kinésithérapeute" autocomplete="off" autofocus />
                </FormField>
                <FormField label="Code" :error="form.errors.code" hint="ne change plus ensuite" required>
                    <Input
                        :model-value="form.code"
                        class="font-mono uppercase"
                        placeholder="KINESITHERAPEUTE"
                        autocomplete="off"
                        spellcheck="false"
                        @update:model-value="onCodeInput"
                    />
                </FormField>
            </div>

            <p
                v-if="form.code && (! codeValid || codeTaken)"
                class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100"
            >
                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                <span v-if="codeTaken">
                    Le code « {{ normalizedCode }} » est déjà pris par « {{ codeTaken.name }} »<template v-if="codeTaken.archived">, un rôle archivé : restaurez-le plutôt que d’en créer un second</template>.
                </span>
                <span v-else>Le code s’écrit en majuscules, sans accent ni espace : lettres, chiffres et « _ », en commençant par une lettre.</span>
            </p>

            <fieldset>
                <legend class="mb-2 text-sm font-medium text-foreground">Point de départ</legend>
                <div class="grid gap-3 md:grid-cols-2">
                    <button
                        type="button"
                        :class="cn(
                            'flex items-start gap-3 rounded-xl border p-4 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            start === 'empty' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:bg-accent',
                        )"
                        :aria-pressed="start === 'empty'"
                        @click="start = 'empty'"
                    >
                        <FilePlus2 :class="cn('mt-0.5 h-5 w-5 shrink-0', start === 'empty' ? 'text-primary' : 'text-muted-foreground')" />
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Partir de zéro</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">Aucune permission : vous cochez ensuite ce que le métier fait.</span>
                        </span>
                    </button>
                    <button
                        type="button"
                        :class="cn(
                            'flex items-start gap-3 rounded-xl border p-4 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            start === 'copy' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:bg-accent',
                        )"
                        :aria-pressed="start === 'copy'"
                        :disabled="! sourceRoles.length"
                        @click="chooseCopy"
                    >
                        <Copy :class="cn('mt-0.5 h-5 w-5 shrink-0', start === 'copy' ? 'text-primary' : 'text-muted-foreground')" />
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Copier le socle d’un rôle</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">Ses permissions sont recopiées au moment de la création, sans lien ensuite.</span>
                        </span>
                    </button>
                </div>

                <div v-if="start === 'copy'" class="mt-4 max-w-md">
                    <FormField label="Rôle à copier" :error="form.errors.permission_ids">
                        <Select v-model="copyFrom" :options="copyOptions" class="w-full" />
                    </FormField>
                </div>
            </fieldset>

            <div class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground">
                    <strong class="tabular-nums text-foreground">{{ form.permission_ids.length }}</strong>
                    permission{{ form.permission_ids.length > 1 ? 's' : '' }} accordée{{ form.permission_ids.length > 1 ? 's' : '' }} à la création.
                </p>
                <div class="flex gap-2">
                    <Button type="button" variant="outline" :disabled="form.processing" @click="emit('cancel')">Annuler</Button>
                    <Button type="submit" variant="primary" :disabled="! canSubmit">
                        <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                        <Check v-else class="h-4 w-4" />
                        {{ form.processing ? 'Création…' : 'Créer le rôle' }}
                    </Button>
                </div>
            </div>
        </form>
    </Card>
</template>
