<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import { BookA, History, Plus, Trash2, TriangleAlert, Undo2 } from 'lucide-vue-next';
import { jsonRequest } from '@/utilities/jsonRequest';

/**
 * ADR-241 — le dictionnaire des abréviations d'un site (celui de RIVO, puis
 * le sien, qui l'emporte) et les décisions mémorisées « même produit / deux
 * produits ». Tout passe par l'API du site.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    siteCode: { type: String, required: true },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open']);

const base = computed(() => `/super-admin/pharmacy-suppliers/${props.siteCode}`);
const tab = ref('dictionary');
const loading = ref(false);
const error = ref('');
const notice = ref('');
const dictionary = ref({ built_in: [], site: [] });
const decisions = ref([]);
const showBuiltIn = ref(false);
const form = ref({ term: '', canonical: '', note: '' });
const formErrors = ref({});
const saving = ref(false);
// Une décision change le comparateur : il se relit en fermant.
const changed = ref(false);

const load = async () => {
    loading.value = true;
    error.value = '';
    const [synonyms, decided] = await Promise.all([
        jsonRequest(`${base.value}/synonyms`),
        jsonRequest(`${base.value}/equivalences`),
    ]);
    loading.value = false;

    if (!synonyms.ok || !decided.ok) {
        error.value = (synonyms.ok ? decided : synonyms).message;

        return;
    }

    dictionary.value = synonyms.data ?? { built_in: [], site: [] };
    decisions.value = decided.data ?? [];
};

watch(() => props.open, (open) => {
    if (open) {
        notice.value = '';
        changed.value = false;
        load();
    } else if (changed.value) {
        router.reload({ only: ['medicines', 'toReconcile', 'proposedByAi'] });
    }
});

const addSynonym = async () => {
    saving.value = true;
    formErrors.value = {};
    const result = await jsonRequest(`${base.value}/synonyms`, { method: 'POST', body: form.value });
    saving.value = false;

    if (!result.ok) {
        formErrors.value = Object.fromEntries(Object.entries(result.errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]));
        if (!Object.keys(formErrors.value).length) error.value = result.message;

        return;
    }

    dictionary.value = result.data ?? dictionary.value;
    notice.value = result.message;
    form.value = { term: '', canonical: '', note: '' };
    changed.value = true;
};

const removeSynonym = async (synonym) => {
    const result = await jsonRequest(`${base.value}/synonyms/${synonym.uuid}`, { method: 'DELETE' });

    if (!result.ok) {
        error.value = result.message;

        return;
    }

    dictionary.value = result.data ?? dictionary.value;
    notice.value = result.message;
    changed.value = true;
};

const forget = async (decision) => {
    const result = await jsonRequest(`${base.value}/equivalences/${decision.uuid}`, { method: 'DELETE' });

    if (!result.ok) {
        error.value = result.message;

        return;
    }

    decisions.value = decisions.value.filter((item) => item.uuid !== decision.uuid);
    notice.value = result.message;
    changed.value = true;
};

const statusTone = (status) => ({ SAME: 'success', DIFFERENT: 'destructive', PROPOSED: 'warning' }[status] ?? 'secondary');
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '');
</script>

<template>
    <Dialog
        :open="open"
        title="Reconnaître le même produit"
        description="Les abréviations que la règle déplie, et ce que vous avez déjà dit de certaines paires."
        size="xl"
        @update:open="(value) => emit('update:open', value)"
    >
        <p v-if="error" class="mb-3 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />{{ error }}
        </p>
        <p v-else-if="notice" class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200" role="status">{{ notice }}</p>

        <Tabs v-model="tab">
            <TabsList>
                <TabsTrigger value="dictionary"><BookA class="h-4 w-4" />Dictionnaire <span class="tabular-nums opacity-70">· {{ dictionary.site.length }}</span></TabsTrigger>
                <TabsTrigger value="decisions"><History class="h-4 w-4" />Décisions <span class="tabular-nums opacity-70">· {{ decisions.length }}</span></TabsTrigger>
            </TabsList>

            <TabsContent value="dictionary" class="mt-4 space-y-4">
                <p class="text-sm text-muted-foreground">
                    Une abréviation se lit comme le mot qu’elle remplace : « cp » = « comprimé ». La règle ignore déjà les accents, la casse,
                    l’ordre des mots et les unités (500 mg = 0,5 g). Ajoutez ici ce que vos fournisseurs écrivent autrement.
                </p>

                <form v-if="canManage" class="grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-[8rem_1fr_1fr_auto] sm:items-start" @submit.prevent="addSynonym">
                    <div>
                        <Input v-model="form.term" placeholder="Abréviation" maxlength="40" aria-label="Abréviation" />
                        <p v-if="formErrors.term" class="mt-1 text-xs text-destructive">{{ formErrors.term }}</p>
                    </div>
                    <div>
                        <Input v-model="form.canonical" placeholder="Se lit… (ex. paracetamol)" maxlength="60" aria-label="Forme complète" />
                        <p v-if="formErrors.canonical" class="mt-1 text-xs text-destructive">{{ formErrors.canonical }}</p>
                    </div>
                    <Input v-model="form.note" placeholder="Remarque (facultatif)" maxlength="255" aria-label="Remarque" />
                    <Button type="submit" :disabled="saving || !form.term.trim() || !form.canonical.trim()"><Plus class="h-4 w-4" />Ajouter</Button>
                </form>

                <div>
                    <p class="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">Ce site</p>
                    <p v-if="loading" class="text-sm text-muted-foreground">Lecture…</p>
                    <p v-else-if="!dictionary.site.length" class="text-sm text-muted-foreground">Aucune abréviation propre à ce site.</p>
                    <ul v-else class="divide-y divide-border rounded-lg border border-border">
                        <li v-for="synonym in dictionary.site" :key="synonym.uuid" class="flex items-center gap-3 px-3 py-2 text-sm">
                            <span class="font-mono font-semibold text-foreground">{{ synonym.term }}</span>
                            <span class="text-muted-foreground">=</span>
                            <span class="min-w-0 flex-1 truncate text-foreground">{{ synonym.canonical }}<span v-if="synonym.note" class="ms-2 text-xs text-muted-foreground">{{ synonym.note }}</span></span>
                            <span v-if="synonym.author" class="hidden text-xs text-muted-foreground sm:inline">{{ synonym.author }}</span>
                            <button v-if="canManage" type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive" :aria-label="`Retirer ${synonym.term}`" @click="removeSynonym(synonym)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </li>
                    </ul>
                </div>

                <div>
                    <button type="button" class="text-xs font-bold uppercase tracking-wide text-muted-foreground hover:text-foreground" :aria-expanded="showBuiltIn" @click="showBuiltIn = !showBuiltIn">
                        {{ showBuiltIn ? '▾' : '▸' }} Livré avec RIVO · {{ dictionary.built_in.length }}
                    </button>
                    <div v-if="showBuiltIn" class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="synonym in dictionary.built_in" :key="synonym.term" class="rounded-md border border-border px-2 py-0.5 text-xs text-muted-foreground">
                            <span class="font-mono text-foreground">{{ synonym.term }}</span> = {{ synonym.canonical }}
                        </span>
                    </div>
                </div>
            </TabsContent>

            <TabsContent value="decisions" class="mt-4">
                <p class="mb-3 text-sm text-muted-foreground">Oublier une décision rend la paire à ce que la règle en dit.</p>
                <p v-if="loading" class="text-sm text-muted-foreground">Lecture…</p>
                <p v-else-if="!decisions.length" class="text-sm text-muted-foreground">Aucune décision mémorisée.</p>
                <ul v-else class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="decision in decisions" :key="decision.uuid" class="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
                        <Badge :variant="statusTone(decision.status)">{{ decision.status_label }}</Badge>
                        <span class="min-w-0 flex-1">
                            <span class="font-medium text-foreground">{{ decision.label }}</span>
                            <span class="text-xs text-muted-foreground"> · {{ decision.supplier_name }}</span>
                            <span class="mx-1.5 text-muted-foreground">↔</span>
                            <span class="font-medium text-foreground">{{ decision.other_label }}</span>
                            <span class="text-xs text-muted-foreground"> · {{ decision.against_clinic ? 'catalogue de la clinique' : decision.other_supplier_name }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ decision.decided_by ?? (decision.source === 'AI' ? 'IA' : '') }}<span v-if="decision.decided_at"> · {{ formatDate(decision.decided_at) }}</span><span v-if="decision.reason"> · {{ decision.reason }}</span>
                            </span>
                        </span>
                        <Button v-if="canManage" type="button" variant="ghost" size="sm" @click="forget(decision)"><Undo2 class="h-4 w-4" />Oublier</Button>
                    </li>
                </ul>
            </TabsContent>
        </Tabs>
    </Dialog>
</template>
