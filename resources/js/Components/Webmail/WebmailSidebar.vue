<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Building2,
    Eye,
    FileText,
    Folder,
    Info,
    LogOut,
    Pencil,
    Plus,
    ShieldCheck,
    SquarePen,
    Tag,
    UserRound,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Popover from '@/Components/Shadcn/Popover.vue';
import { FOLDER_ICONS } from '@/Components/Webmail/folderIcons';
import { cn } from '@/lib/cn';
import { avatarTone, folderUrl, formatSize, initialsOf, labelColor, quotaPercent, WEBMAIL_BASE, WEBMAIL_CACHE_TAG, WEBMAIL_STATIC_PROPS } from '@/utilities/webmail';

/**
 * ADR-195 — la colonne de gauche : écrire, les dossiers et leurs non-lus, les
 * libellés du compte, les collègues joignables et l'espace utilisé.
 */
const props = defineProps({
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    templatesCount: { type: Number, default: 0 },
    quota: { type: Object, default: null },
    current: { type: String, default: 'reception' },
    /** Le dossier demandé, pendant que sa liste arrive : il s'allume tout de suite. */
    pendingFolder: { type: String, default: null },
    activeLabel: { type: String, default: null },
    mailbox: { type: Object, required: true },
});
const emit = defineEmits(['compose', 'write-to', 'new-label', 'edit-label', 'manage-templates', 'logout', 'navigate']);

const ICONS = FOLDER_ICONS;

const system = computed(() => props.folders.filter((folder) => !folder.custom));
const custom = computed(() => props.folders.filter((folder) => folder.custom));
const percent = computed(() => quotaPercent(props.quota));

const showAllContacts = ref(false);
const visibleContacts = computed(() => (showAllContacts.value ? props.contacts : props.contacts.slice(0, 5)));

/** Le dossier allumé : celui qu'on vient de demander, sinon celui qui est affiché. */
const shown = computed(() => props.pendingFolder ?? props.current);
const labelShown = computed(() => (props.pendingFolder ? null : props.activeLabel));

/**
 * Un dossier se prépare dès qu'on le survole (préchargement, gardé 30 s) : le clic
 * trouve souvent sa liste déjà arrivée. La page reste en place pendant le chargement.
 */
const folderLink = { preserveScroll: true, preserveState: true, except: WEBMAIL_STATIC_PROPS, prefetch: true, cacheFor: '30s', cacheTags: WEBMAIL_CACHE_TAG };

/** Un dossier se compte par ses non-lus ; les brouillons, par leur nombre. */
const counter = (folder) => (folder.role === 'drafts' ? folder.total : folder.unseen);
</script>

<template>
    <nav class="flex h-full flex-col gap-5" aria-label="Dossiers de la messagerie">
        <!-- La boîte ouverte : titulaire, adresse, et ce qu'on peut faire d'elle. -->
        <div class="rounded-xl border border-border bg-muted/30 p-3">
            <div class="flex items-center gap-3">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold', avatarTone(mailbox.address))" aria-hidden="true">
                    {{ initialsOf({ name: mailbox.owner, email: mailbox.address }) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-foreground">{{ mailbox.owner }}</p>
                    <p class="truncate text-xs text-muted-foreground" :title="mailbox.address">{{ mailbox.address }}</p>
                </div>
                <div class="flex shrink-0 items-center">
                    <Button v-if="mailbox.can_switch" :as="Link" :href="`${WEBMAIL_BASE}/connexion?changer=1`" variant="ghost" size="xs" icon title="Ouvrir une autre boîte" aria-label="Ouvrir une autre boîte">
                        <ArrowLeftRight class="h-4 w-4" aria-hidden="true" />
                    </Button>
                    <!-- La boîte du portail ne se ferme pas : son mot de passe vit dans le .env, rien n'a été saisi. -->
                    <Button v-if="!mailbox.portal" type="button" variant="ghost" size="xs" icon :title="mailbox.own === false ? 'Fermer cette boîte (le mot de passe sera redemandé)' : 'Fermer ma boîte (le mot de passe sera redemandé)'" :aria-label="mailbox.own === false ? 'Fermer cette boîte' : 'Fermer ma boîte'" @click="emit('logout')">
                        <LogOut class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <!-- La boîte du portail, partagée par les Super Admins : une pastille neutre, le détail au clic. -->
            <Popover v-if="mailbox.portal" align="start" width-class="w-[min(18rem,calc(100vw-2rem))]">
                <template #trigger>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex max-w-full items-center gap-1.5 rounded-full border border-primary/30 bg-primary/5 px-2.5 py-1 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="Boîte du portail : ce que cela implique"
                    >
                        <Building2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate">Boîte du portail</span>
                        <Info class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                    </button>
                </template>
                <div class="flex items-start gap-2.5 p-4 text-xs leading-5 text-muted-foreground">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                    <p>
                        <strong class="block text-sm font-semibold text-foreground">Boîte du portail</strong>
                        L’adresse du Super Admin, réglée dans le .env du portail : elle s’ouvre sans mot de passe à saisir. Chaque envoi est enregistré dans l’audit à votre nom.
                    </p>
                </div>
            </Popover>

            <!-- ADR-195 — la boîte d'un autre employé se dit en permanence : une pastille, le détail au clic. -->
            <Popover v-if="mailbox.own === false" align="start" width-class="w-[min(18rem,calc(100vw-2rem))]">
                <template #trigger>
                    <button
                        type="button"
                        class="mt-2.5 inline-flex max-w-full items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800 transition-colors hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60"
                        aria-label="Boîte d’un employé : ce que cela implique"
                    >
                        <Eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate">Boîte d’un employé<template v-if="mailbox.site_name"> · {{ mailbox.site_name }}</template></span>
                        <Info class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                    </button>
                </template>
                <div class="flex items-start gap-2.5 p-4 text-xs leading-5 text-muted-foreground">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                    <p>
                        <strong class="block text-sm font-semibold text-foreground">Boîte d’un employé</strong>
                        Vous lisez et écrivez à la place de {{ mailbox.owner }}<template v-if="mailbox.site_name"> ({{ mailbox.site_name }})</template>. Chaque ouverture et chaque envoi sont enregistrés dans l’audit à votre nom.
                    </p>
                </div>
            </Popover>
        </div>

        <Button type="button" class="w-full justify-center" @click="emit('compose')">
            <SquarePen class="h-4 w-4" aria-hidden="true" /> Nouveau message
        </Button>

        <ul class="space-y-0.5">
            <li v-for="folder in system" :key="folder.key">
                <Link
                    :href="folderUrl(folder.key)"
                    v-bind="folderLink"
                    :aria-current="shown === folder.key && !labelShown ? 'page' : undefined"
                    :class="cn(
                        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        shown === folder.key && !labelShown ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )"
                    @click="emit('navigate')"
                >
                    <component :is="ICONS[folder.icon] ?? Folder" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1 truncate">{{ folder.name }}</span>
                    <span
                        v-if="counter(folder) > 0"
                        :class="cn('inline-flex min-w-[20px] items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums', folder.role === 'drafts' ? 'bg-muted text-muted-foreground' : 'bg-destructive text-destructive-foreground')"
                        :aria-label="folder.role === 'drafts' ? `${counter(folder)} brouillons` : `${counter(folder)} non lus`"
                    >{{ counter(folder) }}</span>
                </Link>
            </li>
        </ul>

        <div v-if="custom.length">
            <p class="mb-1 px-3 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Dossiers</p>
            <ul class="space-y-0.5">
                <li v-for="folder in custom" :key="folder.key">
                    <Link
                        :href="folderUrl(folder.key)"
                        v-bind="folderLink"
                        :aria-current="shown === folder.key && !labelShown ? 'page' : undefined"
                        :class="cn('flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors', shown === folder.key && !labelShown ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"
                        @click="emit('navigate')"
                    >
                        <Folder class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1 truncate">{{ folder.name }}</span>
                        <span v-if="folder.unseen > 0" class="inline-flex min-w-[20px] items-center justify-center rounded-full bg-destructive px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-destructive-foreground" :aria-label="`${folder.unseen} non lus`">{{ folder.unseen }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between px-3">
                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Libellés</p>
                <Button type="button" variant="ghost" size="xs" icon title="Nouveau libellé" aria-label="Nouveau libellé" @click="emit('new-label')">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
            </div>
            <ul v-if="labels.length" class="space-y-0.5">
                <li v-for="label in labels" :key="label.uuid" class="group relative">
                    <Link
                        :href="folderUrl(current, { label: label.uuid })"
                        v-bind="folderLink"
                        :aria-current="labelShown === label.uuid ? 'page' : undefined"
                        :class="cn('flex items-center gap-3 rounded-lg px-3 py-2 pe-9 text-sm transition-colors', labelShown === label.uuid ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"
                        @click="emit('navigate')"
                    >
                        <span :class="cn('h-2.5 w-2.5 shrink-0 rounded-full', labelColor(label.color).dot)" aria-hidden="true" />
                        <span class="flex-1 truncate">{{ label.name }}</span>
                    </Link>
                    <button
                        type="button"
                        class="absolute end-1.5 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted-foreground opacity-0 transition-opacity hover:bg-background hover:text-foreground focus:opacity-100 group-hover:opacity-100"
                        :aria-label="`Modifier le libellé ${label.name}`"
                        @click="emit('edit-label', label)"
                    >
                        <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <button v-else type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-xs text-muted-foreground hover:bg-accent" @click="emit('new-label')">
                <Tag class="h-3.5 w-3.5" aria-hidden="true" /> Classez vos messages avec des libellés.
            </button>
        </div>

        <div>
            <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground" @click="emit('manage-templates')">
                <FileText class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-start">Modèles de message</span>
                <span v-if="templatesCount" class="text-[11px] font-bold">{{ templatesCount }}</span>
            </button>
        </div>

        <div v-if="contacts.length">
            <p class="mb-1 px-3 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Collègues</p>
            <ul class="space-y-0.5">
                <li v-for="contact in visibleContacts" :key="contact.email">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-1.5 text-start transition-colors hover:bg-accent"
                        :title="`Écrire à ${contact.name}`"
                        @click="emit('write-to', contact)"
                    >
                        <span :class="cn('grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-bold', avatarTone(contact.email))" aria-hidden="true">{{ initialsOf(contact) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-foreground">{{ contact.name }}</span>
                            <span class="block truncate text-[11px] text-muted-foreground">{{ contact.job || contact.email }}</span>
                        </span>
                    </button>
                </li>
            </ul>
            <button v-if="contacts.length > 5" type="button" class="mt-1 px-3 text-xs font-medium text-primary hover:underline" @click="showAllContacts = !showAllContacts">
                {{ showAllContacts ? 'Afficher moins' : `Afficher les ${contacts.length} collègues` }}
            </button>
        </div>
        <p v-else class="flex items-center gap-2 px-3 text-xs text-muted-foreground">
            <UserRound class="h-3.5 w-3.5" aria-hidden="true" /> Aucun autre collègue n’a encore d’adresse active.
        </p>

        <div class="mt-auto rounded-xl border border-border p-3">
            <template v-if="percent !== null">
                <div class="mb-1.5 flex items-center justify-between text-xs">
                    <span class="font-medium text-foreground">Espace utilisé</span>
                    <span :class="cn('font-semibold', percent >= 90 ? 'text-destructive' : 'text-muted-foreground')">{{ percent }} %</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" aria-label="Espace utilisé dans la boîte">
                    <div :class="cn('h-full rounded-full', percent >= 90 ? 'bg-destructive' : percent >= 75 ? 'bg-amber-500' : 'bg-primary')" :style="{ width: `${percent}%` }" />
                </div>
                <p class="mt-1.5 text-[11px] text-muted-foreground">{{ formatSize(quota.used) }} sur {{ formatSize(quota.limit) }}</p>
            </template>
            <p v-else class="text-xs text-muted-foreground">L’hébergeur n’indique pas l’espace utilisé par cette boîte.</p>
        </div>
    </nav>
</template>
