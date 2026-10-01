<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CircleCheck, Minus, Package, Plus, Search, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';

/**
 * Le matériel habituel d'un acte (ADR-072, ADR-142, ADR-169) : ce que les Soins,
 * la Maternité ou le bloc se voient proposer en déclarant cet acte. Une
 * suggestion de saisie, jamais une règle — rien n'est déduit du nom de l'acte,
 * et l'équipe confirme toujours ce qu'elle a réellement utilisé.
 *
 * Les produits proposés sont servis par le serveur : la parapharmacie pour un
 * acte de Soins, tout produit stockable pour la Maternité et le bloc.
 */
const props = defineProps({
    /** Les lignes enregistrées : `{ medicine_uuid, code, name, unit, default_quantity }`. */
    lines: { type: Array, default: () => [] },
    /** Les produits que l'acte peut recevoir. */
    options: { type: Array, default: () => [] },
    /** L'adresse d'enregistrement (site ou portail). */
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});

const LIMIT = 20;
const toLines = (rows) => rows.map((row) => ({
    medicine_uuid: row.medicine_uuid,
    code: row.code,
    name: row.name,
    unit: row.unit,
    default_quantity: Number(row.default_quantity) || 1,
}));

const form = useForm({ consumables: toLines(props.lines) });
// Après un enregistrement, la page renvoie les lignes du serveur : elles redeviennent la référence.
watch(() => props.lines, (rows) => {
    form.consumables = toLines(rows);
    form.defaults();
}, { deep: true });

const search = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const chosen = computed(() => new Set(form.consumables.map((line) => line.medicine_uuid)));
const matches = computed(() => {
    const needles = normalize(search.value).split(/\s+/).filter(Boolean);
    if (! needles.length) return [];

    return props.options
        .filter((option) => ! chosen.value.has(option.medicine_uuid))
        .filter((option) => {
            const haystack = normalize(`${option.name} ${option.code}`);

            return needles.every((needle) => haystack.includes(needle));
        })
        .slice(0, 8);
});

const add = (option) => {
    if (form.consumables.length >= LIMIT) return;
    form.consumables.push({ ...toLines([option])[0], default_quantity: 1 });
    search.value = '';
};
const remove = (index) => form.consumables.splice(index, 1);
const step = (line, delta) => { line.default_quantity = Math.min(1000, Math.max(1, (Number(line.default_quantity) || 1) + delta)); };

const save = () => form
    .transform((data) => ({
        consumables: data.consumables.map((line) => ({
            medicine_uuid: line.medicine_uuid,
            default_quantity: Math.max(1, Number(line.default_quantity) || 1),
        })),
    }))
    .put(props.url, { preserveScroll: true, preserveState: true });

const errors = computed(() => Object.values(form.errors));
</script>

<template>
    <div class="space-y-4">
        <p v-if="! options.length && ! form.consumables.length" class="text-sm text-muted-foreground">
            Aucun produit ne peut encore être associé à cet acte : la Pharmacie doit d’abord le créer.
        </p>

        <ul v-if="form.consumables.length" class="divide-y divide-border rounded-lg border border-border">
            <li v-for="(line, index) in form.consumables" :key="line.medicine_uuid" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-foreground">{{ line.name }}</p>
                    <p class="text-xs text-muted-foreground"><span class="font-mono">{{ line.code }}</span><template v-if="line.unit"> · {{ line.unit }}</template></p>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    <Button type="button" variant="outline" size="xs" icon :disabled="! canEdit || line.default_quantity <= 1" :aria-label="`Diminuer la quantité de ${line.name}`" @click="step(line, -1)"><Minus class="h-3.5 w-3.5" /></Button>
                    <Input v-model.number="line.default_quantity" type="number" min="1" max="1000" inputmode="numeric" class="h-8 w-16 text-center tabular-nums" :disabled="! canEdit" :aria-label="`Quantité habituelle de ${line.name}`" />
                    <Button type="button" variant="outline" size="xs" icon :disabled="! canEdit" :aria-label="`Augmenter la quantité de ${line.name}`" @click="step(line, 1)"><Plus class="h-3.5 w-3.5" /></Button>
                    <Button v-if="canEdit" type="button" variant="ghost" size="xs" icon class="text-muted-foreground hover:text-destructive" :aria-label="`Retirer ${line.name}`" @click="remove(index)"><Trash2 class="h-3.5 w-3.5" /></Button>
                </div>
            </li>
        </ul>
        <p v-else-if="options.length" class="text-sm text-muted-foreground">Aucun matériel associé : l’acte ne propose rien d’office.</p>

        <div v-if="canEdit && options.length" class="space-y-2">
            <IconInput
                v-model="search"
                :icon="Search"
                type="text"
                inputmode="search"
                :disabled="form.consumables.length >= LIMIT"
                :placeholder="form.consumables.length >= LIMIT ? `${LIMIT} produits au plus` : 'Ajouter un produit — nom ou code'"
                aria-label="Ajouter un produit au matériel habituel"
            />
            <ul v-if="matches.length" class="divide-y divide-border rounded-lg border border-border">
                <li v-for="option in matches" :key="option.medicine_uuid">
                    <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start text-sm hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none" @click="add(option)">
                        <span class="flex min-w-0 items-center gap-2.5">
                            <Package class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0">
                                <span class="block truncate text-foreground">{{ option.name }}</span>
                                <span class="block text-xs text-muted-foreground"><span class="font-mono">{{ option.code }}</span><template v-if="option.unit"> · {{ option.unit }}</template></span>
                            </span>
                        </span>
                        <Badge :variant="option.available ? 'outline' : 'secondary'" :class="cn('shrink-0', ! option.available && 'text-muted-foreground')">
                            {{ option.available ? `${option.available_quantity} en stock` : 'Épuisé' }}
                        </Badge>
                    </button>
                </li>
            </ul>
            <p v-else-if="search.trim()" class="text-sm text-muted-foreground">Aucun produit ne correspond.</p>
        </div>

        <FormError v-for="error in errors" :key="error">{{ error }}</FormError>

        <div v-if="canEdit" class="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-muted-foreground">
                {{ form.isDirty ? 'Modifications non enregistrées.' : 'Une suggestion : l’équipe confirme toujours ce qu’elle a utilisé.' }}
            </p>
            <Button type="button" :disabled="form.processing || ! form.isDirty" @click="save">
                <CircleCheck class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer le matériel' }}
            </Button>
        </div>
    </div>
</template>
