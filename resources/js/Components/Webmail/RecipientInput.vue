<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Mail, X } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { avatarTone, initialsOf, isEmail, recipientSuggestions } from '@/utilities/webmail';

/**
 * ADR-195 — un champ de destinataires : une pastille par adresse, les collègues
 * proposés au fil de la frappe, et n'importe quelle autre adresse tapée à la main.
 * La valeur remontée est le texte que le serveur relit (« a@x, Nom <b@y> ») :
 * c'est lui qui décide ce qui est valable.
 *
 * Une adresse en cours de frappe compte déjà (dès qu'elle contient « @ ») : on
 * peut envoyer sans l'avoir validée, et une adresse incomplète retient l'envoi au
 * lieu d'être perdue. Une adresse valable tapée est proposée (« Écrire à … »).
 *
 * Une pastille dont l'adresse n'est pas valable est rouge avant l'envoi.
 * Clavier : Entrée, virgule, point-virgule ou Tab valident l'adresse tapée ;
 * Retour arrière sur un champ vide retire la dernière ; ↑ ↓ parcourent les
 * propositions.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    contacts: { type: Array, default: () => [] },
    id: { type: String, required: true },
    label: { type: String, required: true },
    invalid: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const parse = (value) => String(value ?? '')
    .split(/[,;\n]+/)
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .map((chunk) => {
        const match = chunk.match(/^(.*)<([^>]+)>\s*$/);
        return match ? { name: match[1].trim().replace(/^"|"$/g, ''), email: match[2].trim() } : { name: '', email: chunk };
    });

const chips = ref(parse(props.modelValue));
const draft = ref('');
const focused = ref(false);
const active = ref(0);
const input = ref(null);

const serialize = (list) => list.map((chip) => (chip.name ? `${chip.name} <${chip.email}>` : chip.email)).join(', ');

/** Ce qui est en cours de frappe et ressemble à une adresse : il compte déjà. */
const typed = computed(() => draft.value.trim().replace(/[,;]+$/, ''));
const pending = computed(() => (typed.value.includes('@') ? parse(typed.value) : []));
const current = () => serialize([...chips.value, ...pending.value]);

watch(() => props.modelValue, (value) => {
    if (value !== current()) {
        chips.value = parse(value);
        draft.value = '';
    }
});

const emitChips = () => emit('update:modelValue', current());

watch(pending, emitChips);

const suggestions = computed(() => recipientSuggestions(props.contacts, chips.value, typed.value));

watch(suggestions, () => { active.value = 0; });

const add = (chip) => {
    const email = String(chip.email ?? '').trim();
    // La frappe est vidée d'abord : l'adresse ne doit pas remonter deux fois (pastille + frappe).
    draft.value = '';
    if (!email) return;
    if (!chips.value.some((existing) => existing.email.toLowerCase() === email.toLowerCase())) {
        chips.value = [...chips.value, { name: chip.name ?? '', email }];
        emitChips();
    }
};

const commitDraft = () => {
    const text = typed.value;
    if (!text) return false;
    parse(text).forEach(add);
    draft.value = '';
    return true;
};

const remove = (index) => {
    chips.value = chips.value.filter((_, position) => position !== index);
    emitChips();
    nextTick(() => input.value?.focus());
};

const onKeydown = (event) => {
    if (event.key === 'ArrowDown' && suggestions.value.length) {
        event.preventDefault();
        active.value = (active.value + 1) % suggestions.value.length;
    } else if (event.key === 'ArrowUp' && suggestions.value.length) {
        event.preventDefault();
        active.value = (active.value - 1 + suggestions.value.length) % suggestions.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (suggestions.value[active.value]) add(suggestions.value[active.value]);
        else commitDraft();
    } else if ((event.key === ',' || event.key === ';') && draft.value.trim()) {
        event.preventDefault();
        commitDraft();
    } else if (event.key === 'Tab' && draft.value.trim()) {
        if (suggestions.value[active.value]) add(suggestions.value[active.value]);
        else commitDraft();
    } else if (event.key === 'Backspace' && !draft.value && chips.value.length) {
        remove(chips.value.length - 1);
    } else if (event.key === 'Escape') {
        draft.value = '';
    }
};

const onPaste = (event) => {
    const text = event.clipboardData?.getData('text') ?? '';
    if (/[,;\n]/.test(text)) {
        event.preventDefault();
        parse(text).forEach(add);
    }
};

const onBlur = () => {
    // Laisse le temps à un clic sur une proposition d'être pris.
    setTimeout(() => {
        focused.value = false;
        commitDraft();
    }, 150);
};

const listId = computed(() => `${props.id}-suggestions`);
</script>

<template>
    <div class="relative">
        <div
            :class="cn(
                'flex min-h-[var(--control-h)] w-full flex-wrap items-center gap-1.5 rounded-lg border border-input bg-background px-2 py-1.5 text-sm transition-colors focus-within:ring-2 focus-within:ring-ring/40',
                invalid && 'border-destructive',
            )"
            @click="input?.focus()"
        >
            <span
                v-for="(chip, index) in chips"
                :key="`${chip.email}-${index}`"
                :class="cn(
                    'inline-flex max-w-full items-center gap-1 rounded-full border py-0.5 pe-1 ps-1 text-xs font-medium',
                    isEmail(chip.email)
                        ? 'border-border bg-muted text-foreground'
                        : 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
                )"
                :title="isEmail(chip.email) ? chip.email : `Adresse invalide : ${chip.email}`"
            >
                <span :class="cn('inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold', avatarTone(chip.email))" aria-hidden="true">{{ initialsOf(chip) }}</span>
                <span class="truncate">{{ chip.name || chip.email }}</span>
                <button type="button" class="rounded-full p-0.5 hover:bg-background" :aria-label="`Retirer ${chip.name || chip.email}`" @click.stop="remove(index)">
                    <X class="h-3 w-3" aria-hidden="true" />
                </button>
            </span>
            <input
                :id="id"
                ref="input"
                v-model="draft"
                type="text"
                inputmode="email"
                autocomplete="off"
                role="combobox"
                :aria-label="label"
                :aria-expanded="focused && suggestions.length > 0"
                :aria-controls="listId"
                :aria-invalid="invalid || undefined"
                :placeholder="chips.length ? '' : placeholder"
                class="min-w-[10rem] flex-1 border-0 bg-transparent p-0.5 text-sm outline-none placeholder:text-muted-foreground focus:ring-0"
                @keydown="onKeydown"
                @paste="onPaste"
                @focus="focused = true"
                @blur="onBlur"
            >
        </div>

        <ul
            v-if="focused && suggestions.length"
            :id="listId"
            role="listbox"
            class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-lg border border-border bg-popover py-1 text-sm shadow-lg"
        >
            <li
                v-for="(contact, index) in suggestions"
                :key="contact.email"
                role="option"
                :aria-selected="index === active"
                :class="cn('flex cursor-pointer items-center gap-2.5 px-3 py-2', index === active ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60')"
                @mousedown.prevent="add(contact)"
                @mouseenter="active = index"
            >
                <template v-if="contact.typed">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary" aria-hidden="true"><Mail class="h-3.5 w-3.5" /></span>
                    <span class="min-w-0">
                        <span class="block truncate font-medium">Écrire à {{ contact.email }}</span>
                        <span class="block truncate text-xs text-muted-foreground">Entrée pour l’ajouter</span>
                    </span>
                </template>
                <template v-else>
                    <span :class="cn('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold', avatarTone(contact.email))" aria-hidden="true">{{ initialsOf(contact) }}</span>
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{ contact.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ contact.email }}<template v-if="contact.job"> · {{ contact.job }}</template></span>
                    </span>
                </template>
            </li>
        </ul>
    </div>
</template>
