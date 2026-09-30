<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Coins, Gift, Search, Stethoscope, Users, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * Un article d'avantage à l'acte (ECHO, ECG, CHIR…) : son nom, ce qu'il compte
 * (actes réalisés ou patients référés), son prix unitaire et les actes du
 * catalogue qu'il regroupe. Corriger un article ne réécrit aucun avantage validé.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    article: { type: Object, default: null },
    sources: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open']);

const blank = () => ({ name: '', source: 'PERFORMED', unit_price: '', description: '', catalog_item_uuids: [] });
const form = useForm(blank());
const query = ref('');

watch(() => props.open, (open) => {
    if (! open) return;
    form.clearErrors();
    query.value = '';
    const article = props.article;
    Object.assign(form, article ? {
        name: article.name,
        source: article.source,
        unit_price: String(Number(article.unit_price)),
        description: article.description ?? '',
        catalog_item_uuids: article.items.map((item) => item.uuid),
    } : blank());
    nextTick(() => document.getElementById('advantage-article-name')?.focus());
});

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const byUuid = computed(() => new Map(props.catalog.map((item) => [item.uuid, item])));
const chosen = computed(() => form.catalog_item_uuids.map((uuid) => byUuid.value.get(uuid) ?? { uuid, code: '?', name: 'Acte retiré du catalogue' }));
const shown = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);
    if (! terms.length) return [];

    return props.catalog.filter((item) => {
        const haystack = normalize(`${item.code} ${item.name} ${item.module ?? ''}`);

        return terms.every((term) => haystack.includes(term));
    }).slice(0, 40);
});
const isChosen = (uuid) => form.catalog_item_uuids.includes(uuid);
const toggle = (uuid) => {
    form.catalog_item_uuids = isChosen(uuid) ? form.catalog_item_uuids.filter((item) => item !== uuid) : [...form.catalog_item_uuids, uuid];
};

const pricePreview = computed(() => {
    const value = Number(String(form.unit_price).replace(/\s/g, '').replace(',', '.'));

    return String(form.unit_price).trim() !== '' && Number.isFinite(value) && value >= 0 ? formatMoney(value) : null;
});
const canSubmit = computed(() => form.name.trim() && form.source && pricePreview.value !== null && form.catalog_item_uuids.length > 0);
const sourceIcon = { PERFORMED: Stethoscope, REFERRED: Users };

const close = () => {
    if (! form.processing) emit('update:open', false);
};
const submit = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('update:open', false) };

    if (props.article) form.put(hrUrl(`/administration/bonus/avantages/articles/${props.article.uuid}`), options);
    else form.post(hrUrl('/administration/bonus/avantages/articles'), options);
};
</script>

<template>
    <Dialog
        :open="open"
        size="xl"
        :title="article ? `Modifier « ${article.name} »` : 'Nouvel article d’avantage'"
        description="Chaque acte compté ce mois-ci rapporte le prix unitaire à la personne. RIVO compte ; le RH valide puis marque versé."
        :dismissible="false"
        @update:open="(value) => value || close()"
    >
        <template #icon><Gift class="h-5 w-5" /></template>

        <form id="advantage-article-form" class="grid gap-5" @submit.prevent="canSubmit && submit()">
            <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
                <FormField label="Nom de l’article" required :error="form.errors.name">
                    <Input id="advantage-article-name" v-model="form.name" maxlength="120" placeholder="Ex. ECHO, ECG, CHIR" />
                </FormField>
                <FormField label="Prix unitaire" required :error="form.errors.unit_price">
                    <IconInput v-model="form.unit_price" :icon="Coins" inputmode="decimal" placeholder="Ex. 5 000" />
                    <span v-if="pricePreview" class="mt-1 block text-xs text-muted-foreground">{{ pricePreview }} par acte</span>
                </FormField>
            </div>

            <FormField as="div" label="Ce que l’article compte" required :error="form.errors.source">
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Ce que l’article compte">
                    <button
                        v-for="source in sources"
                        :key="source.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.source === source.value"
                        :class="cn('flex items-start gap-3 rounded-xl border p-3 text-start transition-colors', form.source === source.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40')"
                        @click="form.source = source.value"
                    >
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-lg', form.source === source.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <component :is="sourceIcon[source.value]" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-foreground">{{ source.label }}</span>
                            <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">{{ source.description }}</span>
                        </span>
                    </button>
                </div>
            </FormField>

            <FormField as="div" label="Actes du catalogue regroupés" required :error="form.errors.catalog_item_uuids">
                <div v-if="chosen.length" class="mb-2 flex flex-wrap gap-1.5">
                    <span v-for="item in chosen" :key="item.uuid" class="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 py-0.5 pe-1 ps-2.5 text-xs">
                        <span class="font-mono text-muted-foreground">{{ item.code }}</span>{{ item.name }}
                        <button type="button" class="grid h-5 w-5 place-items-center rounded-full hover:bg-muted" :aria-label="`Retirer ${item.name}`" @click="toggle(item.uuid)"><X class="h-3 w-3" /></button>
                    </span>
                </div>
                <IconInput v-model="query" :icon="Search" placeholder="Chercher un acte : écho, ECG, hernie, cholécystectomie…" />
                <ul v-if="shown.length" class="mt-2 max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="item in shown" :key="item.uuid">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted/50">
                            <Checkbox :model-value="isChosen(item.uuid)" @update:model-value="toggle(item.uuid)" />
                            <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                            <span class="font-mono text-xs text-muted-foreground">{{ item.code }}</span>
                            <span v-if="item.module" class="hidden text-xs text-muted-foreground sm:inline">{{ item.module }}</span>
                        </label>
                    </li>
                </ul>
                <p v-else-if="query.trim()" class="mt-2 text-xs text-muted-foreground">Aucun acte ne correspond.</p>
                <p v-else class="mt-2 text-xs text-muted-foreground">Tapez pour chercher parmi les actes du catalogue. Un même article peut en regrouper plusieurs (toutes les échographies, par exemple).</p>
            </FormField>

            <FormField label="Description" hint="(facultatif)" :error="form.errors.description">
                <Textarea v-model="form.description" :rows="2" maxlength="1000" placeholder="Ex. Référence chirurgie : médecins et infirmiers qui envoient un patient opéré" />
            </FormField>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="close">Annuler</Button>
            <Button type="submit" form="advantage-article-form" :disabled="! canSubmit || form.processing">{{ article ? 'Enregistrer' : 'Créer l’article' }}</Button>
        </template>
    </Dialog>
</template>
