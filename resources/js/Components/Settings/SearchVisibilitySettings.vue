<script setup>
import { computed } from 'vue';
import { Bot, CircleCheck, CircleMinus, ClipboardCopy, CodeXml, ExternalLink, Eye, EyeOff, FileCode, Info, Server, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Label from '@/Components/Shadcn/Label.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { cn } from '@/lib/cn';
import { useToastStore } from '@/stores/toast';

/**
 * Ce que les moteurs de recherche peuvent voir d'un site (ADR-184, amendement du
 * 2026-09-24) : l'interrupteur et son état en tête, les trois consignes qu'il
 * pose, puis ce que le site sert réellement — robots.txt et l'en-tête HTTP —,
 * copiables pour les vérifier dans l'outil d'un moteur.
 */
const props = defineProps({
    form: { type: Object, required: true },
    siteName: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});

const toast = useToastStore();
const hidden = computed(() => Boolean(props.form.search_engines_hidden));

/** Ce que le site servira : les mêmes lignes que `AppSettings::robotsTxt()`. */
const robotsPreview = computed(() => (hidden.value
    ? '# Application privée : aucune page à explorer ni à indexer (ADR-184).\nUser-agent: *\nDisallow: /'
    : 'User-agent: *\nDisallow:'));
const ROBOTS_DIRECTIVES = 'noindex, nofollow, noarchive, nosnippet, noimageindex';
const headerLine = `X-Robots-Tag: ${ROBOTS_DIRECTIVES}`;

const visibilityMeasures = computed(() => [
    { label: 'robots.txt', icon: FileCode, detail: hidden.value ? 'Refuse toute exploration.' : 'Autorise l’exploration.' },
    { label: 'Balise des pages', icon: CodeXml, detail: hidden.value ? '« noindex » dans chaque page.' : 'Aucune consigne.' },
    { label: 'En-tête HTTP', icon: Server, detail: hidden.value ? 'Images, documents et API compris.' : 'Aucune consigne.' },
]);

/** Les outils où l'on demande le retrait d'une page déjà référencée. */
const REMOVAL_TOOLS = [
    { label: 'Google Search Console', href: 'https://search.google.com/search-console/removals' },
    { label: 'Bing Webmaster Tools', href: 'https://www.bing.com/webmasters' },
];

const copy = async (text, what) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copié.`);
    } catch {
        toast.error('La copie a été refusée par le navigateur.');
    }
};
</script>

<template>
    <SettingsSection id="visibilite" title="Moteurs de recherche" :description="`Ce que Google, Bing et les autres moteurs peuvent voir — ${siteName}. Les écrans restent de toute façon protégés par la connexion.`">
        <!-- L'interrupteur, avec l'état qu'il produit sous les yeux. -->
        <div :class="cn('flex items-start gap-4 rounded-xl border p-4 transition-colors sm:items-center sm:p-5', hidden ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-amber-200 bg-amber-50/50 dark:border-amber-900 dark:bg-amber-950/20')">
            <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-lg', hidden ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300')" aria-hidden="true">
                <EyeOff v-if="hidden" class="h-5 w-5" />
                <Eye v-else class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Label for="search-engines-hidden" class="text-base">Masquer l’application</Label>
                    <Badge :variant="hidden ? 'success' : 'warning'">{{ hidden ? 'Masquée des moteurs' : 'Visible par les moteurs' }}</Badge>
                </div>
                <p class="text-sm text-muted-foreground">
                    <template v-if="hidden">Aucune page, image ni document n’est exploré ni indexé : robots.txt refuse tout, et chaque réponse porte la consigne « noindex ».</template>
                    <template v-else>Les moteurs peuvent explorer et indexer les pages publiques du site. Activez pour les en empêcher.</template>
                </p>
            </div>
            <Switch id="search-engines-hidden" v-model="form.search_engines_hidden" :disabled="readonly" class="mt-1 sm:mt-0" />
        </div>

        <p v-if="! hidden" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200" role="status">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />La page de connexion ({{ siteName }}) pourra apparaître dans les résultats de recherche. Rien d’autre n’est visible sans compte.
        </p>

        <SettingsField label="Ce qui est appliqué">
            <ul class="grid gap-3 sm:grid-cols-3" aria-label="Consignes appliquées">
                <li v-for="measure in visibilityMeasures" :key="measure.label" class="flex items-start gap-3 rounded-lg border border-border bg-card p-4">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary" aria-hidden="true">
                        <component :is="measure.icon" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium text-foreground">{{ measure.label }}</p>
                        <p :class="cn('flex items-start gap-1.5 text-[0.8rem] leading-5', hidden ? 'text-emerald-700 dark:text-emerald-300' : 'text-muted-foreground')">
                            <CircleCheck v-if="hidden" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <CircleMinus v-else class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ measure.detail }}</span>
                        </p>
                    </div>
                </li>
            </ul>
            <p class="flex items-start gap-2 text-[0.8rem] leading-5 text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                    Une page déjà référencée peut rester visible quelque temps : son retrait se demande dans l’outil du moteur —
                    <template v-for="(tool, index) in REMOVAL_TOOLS" :key="tool.href">
                        <a :href="tool.href" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-0.5 font-medium text-primary hover:underline">{{ tool.label }}<ExternalLink class="h-3 w-3" aria-hidden="true" /></a><template v-if="index < REMOVAL_TOOLS.length - 1">, </template>
                    </template>.
                </span>
            </p>
        </SettingsField>

        <SettingsField label="Ce que le site sert" :description="hidden ? 'Le fichier lu par les robots, et la consigne ajoutée à chaque réponse.' : 'Le fichier lu par les robots des moteurs.'">
            <div :class="cn('grid gap-3', hidden && 'cq-4xl:grid-cols-2')">
                <div class="flex flex-col overflow-hidden rounded-lg border border-border">
                    <div class="flex items-center justify-between gap-2 border-b border-border bg-muted/50 px-3 py-2">
                        <span class="flex items-center gap-2 text-xs font-medium text-foreground"><Bot class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />robots.txt</span>
                        <Button type="button" variant="ghost" size="sm" class="h-7 px-2 text-xs" title="Copier le contenu de robots.txt" @click="copy(robotsPreview, 'robots.txt')"><ClipboardCopy class="h-3.5 w-3.5" />Copier</Button>
                    </div>
                    <pre class="flex-1 whitespace-pre-wrap break-words bg-muted/20 px-4 py-3 font-mono text-xs leading-5 text-foreground" aria-label="Aperçu de robots.txt">{{ robotsPreview }}</pre>
                </div>
                <div v-if="hidden" class="flex flex-col overflow-hidden rounded-lg border border-border">
                    <div class="flex items-center justify-between gap-2 border-b border-border bg-muted/50 px-3 py-2">
                        <span class="flex items-center gap-2 text-xs font-medium text-foreground"><Server class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />En-tête HTTP de chaque réponse</span>
                        <Button type="button" variant="ghost" size="sm" class="h-7 px-2 text-xs" title="Copier l’en-tête HTTP" @click="copy(headerLine, 'En-tête')"><ClipboardCopy class="h-3.5 w-3.5" />Copier</Button>
                    </div>
                    <pre class="flex-1 whitespace-pre-wrap break-words bg-muted/20 px-4 py-3 font-mono text-xs leading-5 text-foreground" aria-label="En-tête X-Robots-Tag">{{ headerLine }}</pre>
                </div>
            </div>
        </SettingsField>
    </SettingsSection>
</template>
