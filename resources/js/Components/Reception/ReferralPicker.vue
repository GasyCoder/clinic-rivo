<script setup>
import { computed } from 'vue';
import { Gift, Handshake, LoaderCircle, Phone, Search, UserRound, Users, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormError from '@/Components/UI/FormError.vue';
import { useReceptionLookup } from '@/composables/useReceptionLookup';
import { cn } from '@/lib/cn';
import { emptyReferral } from '@/utilities/referral';

/**
 * ADR-212 — « Une personne a recommandé la clinique à ce patient ? ». Si oui,
 * on la retrouve parmi le personnel en poste et les partenaires actifs, ou on
 * saisit son nom : elle recevra un cadeau à la clinique. Se note à la création
 * du dossier d'un nouveau patient, jamais après coup (le serveur le refuse).
 *
 * `modelValue` : `{ enabled, mode: 'search' | 'other', chosen, name, phone }`.
 */
const props = defineProps({
    modelValue: { type: Object, default: () => emptyReferral() },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const { query, results, loading, performed, error, search, reset } = useReceptionLookup('/reception/referrers');

const update = (changes) => emit('update:modelValue', { ...props.modelValue, ...changes });
const setEnabled = (enabled) => {
    if (! enabled) reset();
    update({ ...emptyReferral(), enabled });
};
const setMode = (mode) => {
    reset();
    update({ mode, chosen: null, name: '', phone: '' });
};
const choose = (person) => {
    reset();
    update({ chosen: person });
};

const shown = computed(() => results.value);
const SOURCE = {
    EMPLOYEE: { label: 'Personnel', icon: Users },
    PARTNER: { label: 'Partenaire', icon: Handshake },
};
const firstError = computed(() => ['referral', 'referral.source', 'referral.employee_uuid', 'referral.partner_uuid', 'referral.name', 'referral.phone']
    .map((key) => [].concat(props.errors?.[key] ?? [])[0])
    .find(Boolean) ?? '');
</script>

<template>
    <section class="mt-5 w-full overflow-hidden rounded-md border border-border">
        <label for="referral-enabled" class="flex cursor-pointer items-start gap-3 bg-muted/35 px-5 py-4">
            <Checkbox id="referral-enabled" class="mt-0.5" :model-value="modelValue.enabled" @update:model-value="setEnabled" />
            <span class="min-w-0">
                <span class="flex items-center gap-2 text-sm font-bold text-foreground"><Gift class="h-4 w-4 text-primary" />Une personne a recommandé la clinique à ce patient ?</span>
                <span class="mt-1 block text-xs text-muted-foreground">Un membre du personnel, un partenaire ou une autre personne : elle reçoit un cadeau à la clinique.</span>
            </span>
        </label>

        <div v-if="modelValue.enabled" class="space-y-4 border-t border-border p-5">
            <div class="inline-flex rounded-lg bg-muted p-1" role="group" aria-label="Qui a recommandé">
                <button
                    v-for="option in [{ value: 'search', label: 'Personnel ou partenaire' }, { value: 'other', label: 'Autre personne' }]"
                    :key="option.value"
                    type="button"
                    :aria-pressed="modelValue.mode === option.value"
                    :class="cn('rounded-md px-3 py-1 text-sm font-medium transition-colors', modelValue.mode === option.value ? 'bg-background text-foreground shadow' : 'text-muted-foreground hover:text-foreground')"
                    @click="setMode(option.value)"
                >{{ option.label }}</button>
            </div>

            <template v-if="modelValue.mode === 'search'">
                <div v-if="modelValue.chosen" class="flex flex-wrap items-center gap-3 rounded-md border border-primary/30 bg-primary/5 px-4 py-3">
                    <component :is="SOURCE[modelValue.chosen.source]?.icon ?? UserRound" class="h-5 w-5 text-primary" />
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2 text-sm font-bold text-foreground">{{ modelValue.chosen.name }}<Badge variant="secondary">{{ SOURCE[modelValue.chosen.source]?.label }}</Badge></span>
                        <span v-if="modelValue.chosen.detail" class="block text-xs text-muted-foreground">{{ modelValue.chosen.detail }}</span>
                    </span>
                    <Button type="button" size="sm" variant="ghost" @click="update({ chosen: null })"><X class="h-4 w-4" />Changer</Button>
                </div>
                <template v-else>
                    <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="search">
                        <IconInput v-model="query" size="lg" :icon="Search" placeholder="Nom, matricule ou téléphone…" autocomplete="off" aria-label="Rechercher qui a recommandé la clinique" />
                        <Button size="lg" type="submit" class="justify-center" :disabled="query.trim().length < 2 || loading">
                            <component :is="loading ? LoaderCircle : Search" :class="cn('h-4 w-4', loading && 'animate-spin')" />{{ loading ? 'Recherche…' : 'Rechercher' }}
                        </Button>
                    </form>
                    <FormError v-if="error">{{ error }}</FormError>
                    <ul v-if="performed && shown.length" class="divide-y divide-border overflow-hidden rounded-md border border-border">
                        <li v-for="person in shown" :key="`${person.source}-${person.uuid}`">
                            <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-primary/5" @click="choose(person)">
                                <component :is="SOURCE[person.source]?.icon ?? UserRound" class="h-4 w-4 shrink-0 text-muted-foreground" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-foreground">{{ person.name }}</span>
                                    <span v-if="person.detail" class="block truncate text-xs text-muted-foreground">{{ person.detail }}</span>
                                </span>
                                <Badge variant="outline">{{ SOURCE[person.source]?.label }}</Badge>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="performed && ! loading && ! error" class="rounded-md border border-dashed border-border px-4 py-5 text-center text-sm text-muted-foreground">
                        Personne ne correspond.
                        <button type="button" class="font-semibold text-primary hover:underline" @click="setMode('other')">Saisir son nom</button>
                    </p>
                </template>
            </template>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <FormField label="Nom de la personne" required>
                    <IconInput :model-value="modelValue.name" size="lg" :icon="UserRound" maxlength="255" placeholder="Nom complet" autocomplete="off" @update:model-value="(value) => update({ name: value ?? '' })" />
                </FormField>
                <FormField label="Téléphone" hint="(facultatif)">
                    <IconInput :model-value="modelValue.phone" size="lg" :icon="Phone" type="tel" maxlength="40" placeholder="Pour la prévenir du cadeau" autocomplete="off" @update:model-value="(value) => update({ phone: value ?? '' })" />
                </FormField>
            </div>

            <FormError v-if="firstError">{{ firstError }}</FormError>
        </div>
    </section>
</template>
