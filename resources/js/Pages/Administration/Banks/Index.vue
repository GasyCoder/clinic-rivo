<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    CircleCheck,
    CircleOff,
    Globe,
    Hash,
    Landmark,
    Layers,
    MapPin,
    Pencil,
    Phone,
    Plus,
    RotateCcw,
    Search,
    Settings,
    StickyNote,
    Users,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * ADR-213 — le module Banques : la liste des banques du site, que la fiche d'un
 * employé propose pour son compte bancaire. « BOA », « Bank of Africa » et
 * « BANK OF AFRICA » ne font plus trois banques : le serveur refuse un doublon
 * et nomme la banque qui existe déjà. Mêmes droits que les autres référentiels
 * RH (`hr_settings.*`) ; ouvert aussi depuis le portail (ADR-187).
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    banks: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const basePath = computed(() => hrUrl('/administration/banks'));

/* Filtres : la liste est courte et servie entière ; filtrer ne recharge rien. */
const query = ref('');
const statusFilter = ref('current');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const stateOf = (bank) => (bank.archived ? 'archived' : bank.active ? 'active' : 'inactive');

const counts = computed(() => ({
    current: props.banks.filter((bank) => ! bank.archived).length,
    active: props.banks.filter((bank) => stateOf(bank) === 'active').length,
    inactive: props.banks.filter((bank) => stateOf(bank) === 'inactive').length,
    archived: props.banks.filter((bank) => bank.archived).length,
}));
const cards = [
    { value: 'current', label: 'En service', hint: 'actives et désactivées', icon: Layers, tone: 'bg-primary/10 text-primary' },
    { value: 'active', label: 'Actives', hint: 'proposées aux fiches', icon: CircleCheck, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
    { value: 'inactive', label: 'Désactivées', hint: 'plus proposées', icon: CircleOff, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
    { value: 'archived', label: 'Archivées', hint: 'restaurables', icon: Archive, tone: 'bg-muted text-muted-foreground' },
];

const shown = computed(() => {
    const terms = normalize(query.value).split(/\s+/).filter(Boolean);

    return props.banks.filter((bank) => {
        if (statusFilter.value === 'current' && bank.archived) return false;
        if (statusFilter.value !== 'current' && stateOf(bank) !== statusFilter.value) return false;
        const haystack = normalize(`${bank.code} ${bank.name} ${bank.bank_code ?? ''} ${bank.swift_code ?? ''}`);

        return terms.every((term) => haystack.includes(term));
    });
});
const accounts = computed(() => props.banks.filter((bank) => ! bank.archived).reduce((sum, bank) => sum + bank.employees_count, 0));

/* ------------------------------------------------------------------ */
/* Ajouter et modifier                                                 */
/* ------------------------------------------------------------------ */

const editing = ref(null);
const dialogOpen = ref(false);
const form = useForm({ code: '', name: '', bank_code: '', swift_code: '', phone: '', address: '', notes: '', position: '', active: true });

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
    nextTick(() => document.getElementById('bank-code')?.focus());
};
const openEdit = (bank) => {
    editing.value = bank;
    form.clearErrors();
    Object.assign(form, {
        code: bank.code, name: bank.name, bank_code: bank.bank_code ?? '', swift_code: bank.swift_code ?? '',
        phone: bank.phone ?? '', address: bank.address ?? '', notes: bank.notes ?? '', position: bank.position ?? '', active: bank.active,
    });
    dialogOpen.value = true;
    nextTick(() => document.getElementById('bank-name')?.focus());
};
const closeDialog = () => {
    if (! form.processing) dialogOpen.value = false;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { dialogOpen.value = false; } };
    const payload = (data) => ({ ...data, position: data.position === '' ? null : data.position });

    if (editing.value) {
        form.transform(payload).put(`${basePath.value}/${editing.value.uuid}`, options);
        return;
    }
    form.transform(payload).post(basePath.value, options);
};

/* ------------------------------------------------------------------ */
/* Archiver et restaurer                                               */
/* ------------------------------------------------------------------ */

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (bank) => {
    archiving.value = bank;
    archiveForm.reset();
    archiveForm.clearErrors();
};
const closeArchive = () => {
    if (! archiveForm.processing) archiving.value = null;
};
const confirmArchive = () => archiveForm.delete(`${basePath.value}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});

const restoring = ref(null);
const restore = (bank) => {
    restoring.value = bank.uuid;
    router.post(`${basePath.value}/${bank.uuid}/restore`, {}, {
        preserveScroll: true,
        onFinish: () => { restoring.value = null; },
    });
};

const accountsLine = (bank) => (bank.employees_count
    ? `${bank.employees_count} compte${bank.employees_count > 1 ? 's' : ''} du personnel`
    : 'Aucun compte du personnel');
</script>

<template>
    <Head title="Banques" />
    <div class="w-full space-y-5">
        <PageHeader eyebrow="Ressources humaines · Référentiels" title="Banques" description="Les banques où le personnel reçoit sa rémunération. La fiche d’un employé choisit sa banque dans cette liste : une seule écriture par banque." :icon="Landmark">
            <template #actions>
                <Button v-if="can('hr_settings.view')" :as="Link" :href="hrUrl('/administration/settings')" variant="outline">
                    <Settings class="h-4 w-4" />Autres paramètres
                </Button>
                <Button v-if="can('hr_settings.create')" type="button" @click="openCreate">
                    <Plus class="h-4 w-4" />Nouvelle banque
                </Button>
            </template>
        </PageHeader>

        <!-- Compteurs : chaque carte est un filtre. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" role="group" aria-label="Filtrer par état">
            <button
                v-for="card in cards"
                :key="card.value"
                type="button"
                :aria-pressed="statusFilter === card.value"
                :class="cn(
                    'relative flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    statusFilter === card.value ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                )"
                @click="statusFilter = card.value"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ counts[card.value] }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
                <Check v-if="statusFilter === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Users class="h-4 w-4" />
                    <span><strong class="font-semibold text-foreground">{{ accounts }}</strong> compte(s) du personnel rattaché(s) à une banque</span>
                </p>
                <div class="relative w-full sm:w-72">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Rechercher une banque…" aria-label="Rechercher une banque" class="pe-9" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <ul v-if="shown.length" class="divide-y divide-border">
                <li v-for="bank in shown" :key="bank.uuid" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors hover:bg-accent/30">
                    <span :class="cn('grid h-11 min-w-11 shrink-0 place-items-center rounded-lg px-2 font-mono text-xs font-bold', bank.archived ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary')">{{ bank.code }}</span>
                    <div class="min-w-0 flex-1 basis-52">
                        <p class="flex flex-wrap items-center gap-2">
                            <span :class="cn('text-sm font-semibold', bank.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ bank.name }}</span>
                            <Badge v-if="bank.archived" variant="outline">Archivée</Badge>
                            <Badge v-else-if="! bank.active" variant="warning">Désactivée</Badge>
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><Users class="h-3 w-3" />{{ accountsLine(bank) }}</span>
                            <span v-if="bank.bank_code" class="inline-flex items-center gap-1 font-mono"><Hash class="h-3 w-3" />Code banque {{ bank.bank_code }}</span>
                            <span v-if="bank.swift_code" class="inline-flex items-center gap-1 font-mono"><Globe class="h-3 w-3" />{{ bank.swift_code }}</span>
                            <span v-if="bank.phone" class="inline-flex items-center gap-1"><Phone class="h-3 w-3" />{{ bank.phone }}</span>
                            <span v-if="bank.address" class="inline-flex items-center gap-1"><MapPin class="h-3 w-3" />{{ bank.address }}</span>
                        </p>
                        <p v-if="bank.notes && ! bank.archived" class="mt-1 flex items-start gap-1 text-xs leading-5 text-muted-foreground"><StickyNote class="mt-0.5 h-3 w-3 shrink-0" />{{ bank.notes }}</p>
                        <p v-if="bank.archived && bank.delete_reason" class="mt-1 text-xs text-muted-foreground">Motif : {{ bank.delete_reason }}</p>
                    </div>
                    <div class="ms-auto flex shrink-0 items-center gap-1">
                        <template v-if="! bank.archived">
                            <Button v-if="can('hr_settings.update')" type="button" size="sm" icon variant="ghost" :title="`Modifier ${bank.code}`" :aria-label="`Modifier ${bank.code}`" @click="openEdit(bank)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button v-if="can('hr_settings.archive')" type="button" size="sm" icon variant="ghost" class="hover:text-destructive" :title="`Archiver ${bank.code}`" :aria-label="`Archiver ${bank.code}`" @click="openArchive(bank)">
                                <Archive class="h-4 w-4" />
                            </Button>
                        </template>
                        <Button v-else-if="can('hr_settings.restore')" type="button" size="sm" variant="outline" :disabled="restoring === bank.uuid" @click="restore(bank)">
                            <RotateCcw class="h-4 w-4" />Restaurer
                        </Button>
                    </div>
                </li>
            </ul>

            <div v-else class="px-5 py-14 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Landmark class="h-6 w-6" /></span>
                <p class="mt-3 text-sm font-bold text-foreground">{{ query ? 'Aucun résultat' : banks.length ? 'Rien dans ce filtre' : 'Aucune banque pour l’instant' }}</p>
                <p class="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">
                    {{ query ? 'Essayez le sigle (BOA) ou un mot du nom.' : banks.length ? 'Choisissez un autre filtre au-dessus.' : 'Ajoutez les banques où le personnel reçoit sa rémunération.' }}
                </p>
                <Button v-if="! query && ! banks.length && can('hr_settings.create')" type="button" class="mt-4" @click="openCreate"><Plus class="h-4 w-4" />Nouvelle banque</Button>
            </div>
        </Card>

        <!-- Ajouter / modifier -->
        <Dialog
            :open="dialogOpen"
            :title="editing ? `Modifier « ${editing.code} »` : 'Nouvelle banque'"
            :description="editing ? 'Les fiches qui portent déjà cette banque suivent le nouveau nom.' : 'Une banque qui existe déjà sous un autre nom est refusée, et nommée.'"
            :dismissible="false"
            @update:open="(value) => value || closeDialog()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="editing ? Pencil : Landmark" class="h-5 w-5" /></span>
            </template>
            <form id="bank-form" class="grid gap-4" novalidate @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-[9rem_minmax(0,1fr)]">
                    <FormField label="Sigle" required :error="form.errors.code">
                        <Input id="bank-code" v-model="form.code" maxlength="20" class="font-mono uppercase" placeholder="BOA" :aria-invalid="Boolean(form.errors.code)" />
                    </FormField>
                    <FormField label="Nom complet" required :error="form.errors.name">
                        <IconInput id="bank-name" v-model="form.name" :icon="Landmark" maxlength="150" placeholder="Bank of Africa Madagascar" :aria-invalid="Boolean(form.errors.name)" />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code banque" hint="(RIB, facultatif)" :error="form.errors.bank_code">
                        <IconInput v-model="form.bank_code" :icon="Hash" maxlength="10" inputmode="numeric" class="font-mono" placeholder="00005" />
                    </FormField>
                    <FormField label="Code SWIFT / BIC" hint="(facultatif)" :error="form.errors.swift_code">
                        <IconInput v-model="form.swift_code" :icon="Globe" maxlength="14" class="font-mono uppercase" placeholder="AFRIMGMG" />
                    </FormField>
                    <FormField label="Téléphone" hint="(facultatif)" :error="form.errors.phone">
                        <IconInput v-model="form.phone" :icon="Phone" type="tel" maxlength="50" />
                    </FormField>
                    <FormField label="Agence ou adresse" hint="(facultatif)" :error="form.errors.address">
                        <IconInput v-model="form.address" :icon="MapPin" maxlength="255" />
                    </FormField>
                </div>
                <FormField label="Note" hint="(facultatif)" :error="form.errors.notes">
                    <Textarea v-model="form.notes" :rows="2" maxlength="2000" placeholder="Contact, délai de virement…" />
                </FormField>
                <label v-if="editing" for="bank-active" class="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 px-3.5 py-3">
                    <Checkbox id="bank-active" v-model="form.active" class="mt-0.5" />
                    <span>
                        <span class="block text-sm font-semibold text-foreground">Proposée dans les fiches</span>
                        <span class="block text-xs leading-5 text-muted-foreground">Décochée, les fiches qui la portent la gardent, mais elle n’est plus proposée ailleurs.</span>
                    </span>
                </label>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="form.processing" @click="closeDialog">Annuler</Button>
                <Button type="submit" form="bank-form" :disabled="form.processing || ! form.code.trim() || ! form.name.trim()">
                    <Check class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Ajouter' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver -->
        <Dialog
            :open="archiving !== null"
            :title="archiving ? `Archiver « ${archiving.code} »` : ''"
            description="Rien n’est supprimé : la banque se restaure à tout moment depuis le filtre « Archivées »."
            :dismissible="false"
            @update:open="(value) => value || closeArchive()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="bank-archive-form" class="space-y-4" novalidate @submit.prevent="confirmArchive">
                <p v-if="archiving?.employees_count" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <Users class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ archiving.employees_count }} fiche(s) la portent : elles la gardent, mais elle ne sera plus proposée.</span>
                </p>
                <FormField label="Motif" required :error="archiveForm.errors.reason">
                    <Textarea v-model="archiveForm.reason" :rows="3" maxlength="1000" placeholder="Pourquoi cette banque n’est-elle plus utilisée ?" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="archiveForm.processing" @click="closeArchive">Annuler</Button>
                <Button type="submit" form="bank-archive-form" variant="destructive" :disabled="archiveForm.processing || ! archiveForm.reason.trim()">
                    <Archive class="h-4 w-4" />{{ archiveForm.processing ? 'Archivage…' : 'Archiver' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
