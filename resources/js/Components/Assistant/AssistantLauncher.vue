<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, History, Lightbulb, MessageSquarePlus, Settings2, ShieldCheck, Sparkles, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Sheet from '@/Components/Shadcn/Sheet.vue';
import AssistantComposer from '@/Components/Assistant/AssistantComposer.vue';
import AssistantMessage from '@/Components/Assistant/AssistantMessage.vue';
import { useAssistantChat } from '@/composables/useAssistantChat';
import { cn } from '@/lib/cn';

/**
 * ADR-222 — l'assistant d'aide au logiciel : un bouton en bas à droite, un panneau
 * latéral qui s'ouvre sans quitter la page. Il n'apparaît que si le serveur le dit
 * disponible pour ce compte (`page.props.assistant.available`).
 *
 * Posé dans la mise en page persistante : la conversation survit aux changements
 * de page, et l'assistant sait sur quel écran on se trouve.
 */
const page = usePage();
const available = computed(() => page.props.assistant?.available === true);
// Le Super Administrateur du portail voit le bouton avant tout réglage : le panneau
// lui dit ce qui manque et l'emmène aux réglages. Les autres comptes ne voient rien.
const setup = computed(() => (available.value ? null : page.props.assistant?.setup ?? null));
const shown = computed(() => available.value || setup.value !== null);
const maxLength = computed(() => Number(page.props.assistant?.max_length ?? 1000));

const open = ref(false);
const view = ref('chat');
const question = ref('');
const scroller = ref(null);
const composer = ref(null);

const chat = useAssistantChat();

const pageContext = () => ({
    url: page.url,
    component: page.component,
    hash: typeof window !== 'undefined' ? window.location.hash : '',
});

const scrollToEnd = () => nextTick(() => {
    const element = scroller.value;
    if (element) element.scrollTop = element.scrollHeight;
});

const send = (text = question.value) => {
    const value = String(text ?? '').trim();
    if (value === '' || chat.streaming.value) return;

    question.value = '';
    view.value = 'chat';
    chat.send(value, pageContext());
    scrollToEnd();
};

const newConversation = () => {
    chat.reset();
    view.value = 'chat';
    chat.loadSuggestions(page.url);
    nextTick(() => composer.value?.focus());
};

const showHistory = () => {
    view.value = 'history';
    chat.loadHistory();
};

const openConversation = async (id) => {
    view.value = 'chat';
    await chat.open(id);
    scrollToEnd();
};

watch(open, (value) => {
    if (! value || ! available.value) return;
    if (chat.messages.value.length === 0) chat.loadSuggestions(page.url);
    nextTick(() => composer.value?.focus());
});

// La page change pendant que la conversation est vide : les propositions suivent l'écran.
watch(() => page.url, (url) => {
    if (open.value && available.value && chat.messages.value.length === 0) chat.loadSuggestions(url);
});

// Le défilement suit la réponse, morceau par morceau.
watch(() => {
    const last = chat.messages.value[chat.messages.value.length - 1];

    return `${chat.messages.value.length}|${last?.content?.length ?? 0}|${last?.status ?? ''}`;
}, scrollToEnd);

const dateLabel = (iso) => (iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
</script>

<template>
    <template v-if="shown">
        <button
            type="button"
            :class="cn('fixed bottom-5 end-5 z-[1400] inline-flex items-center gap-2 rounded-full bg-primary px-4 py-3 text-sm font-medium text-primary-foreground shadow-lg transition hover:shadow-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-ring/40 print:hidden',
                open && 'pointer-events-none opacity-0')"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :title="setup ? 'Assistant IA — à configurer' : 'Aide au logiciel'"
            data-assistant-launcher
            @click="open = true"
        >
            <Sparkles class="h-4 w-4" aria-hidden="true" />
            <span class="hidden sm:inline">Assistant</span>
            <span class="sr-only sm:hidden">Ouvrir l’assistant d’aide au logiciel</span>
            <span v-if="setup" class="absolute -end-0.5 -top-0.5 h-3 w-3 rounded-full border-2 border-background bg-amber-500" aria-hidden="true" />
            <span v-if="setup" class="sr-only">(à configurer)</span>
        </button>

        <Sheet
            v-model:open="open"
            title="Assistant RIVO"
            :description="chat.moduleTitle.value ? `Aide au logiciel · ${chat.moduleTitle.value}` : 'Aide à l’utilisation du logiciel'"
            content-class="max-w-lg"
            body-class="flex flex-col p-0"
        >
            <template #icon>
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <Sparkles class="h-4 w-4" />
                </span>
            </template>

            <!-- Pas encore prêt : ce qui manque, et le chemin des réglages (Super Administrateur seulement). -->
            <div v-if="setup" class="flex-1 overflow-y-auto px-4 py-5" data-assistant-setup>
                <div class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-4">
                    <p class="flex items-center gap-2 font-medium text-foreground">
                        <Settings2 class="h-4 w-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                        {{ setup.state === 'disabled' ? 'L’assistant est configuré mais désactivé' : 'L’assistant n’est pas encore configuré' }}
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ setup.state === 'disabled'
                            ? 'Cochez « Assistant activé » dans ses réglages puis enregistrez : le bouton apparaîtra pour les comptes qui ont le droit de s’en servir.'
                            : 'Choisissez un fournisseur, un modèle, collez la clé d’API, testez la connexion, cochez « Assistant activé » et enregistrez.' }}
                    </p>
                    <ol v-if="setup.state !== 'disabled'" class="mt-3 list-decimal space-y-1 ps-5 text-sm text-muted-foreground">
                        <li>Paramètres › <span class="font-medium text-foreground">Assistant IA</span>, puis le site dans l’en-tête (ou le portail).</li>
                        <li>Fournisseur, modèle et clé d’API du fournisseur.</li>
                        <li><span class="font-medium text-foreground">Tester la connexion</span>, cocher « Assistant activé », <span class="font-medium text-foreground">Enregistrer</span>.</li>
                        <li>Recommencer pour chaque site : chacun a ses réglages et sa clé.</li>
                    </ol>
                    <Button :as="Link" :href="setup.url" class="mt-4" size="sm" @click="open = false">
                        Configurer l’assistant<ArrowRight class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </div>
                <p class="mt-4 flex items-start gap-1.5 text-xs text-muted-foreground">
                    <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    Vous voyez ce bouton parce que vous pouvez régler l’assistant. Les autres comptes ne le verront qu’une fois l’assistant activé et configuré sur leur site.
                </p>
            </div>

            <template v-else>
                <div class="flex items-center gap-1 border-b border-border px-3 py-2">
                    <Button type="button" size="sm" :variant="view === 'chat' ? 'secondary' : 'ghost'" @click="view = 'chat'">Discussion</Button>
                    <Button type="button" size="sm" :variant="view === 'history' ? 'secondary' : 'ghost'" @click="showHistory">
                        <History class="h-4 w-4" aria-hidden="true" />Historique
                    </Button>
                    <Button type="button" size="sm" variant="ghost" class="ms-auto" :disabled="chat.streaming.value" @click="newConversation">
                        <MessageSquarePlus class="h-4 w-4" aria-hidden="true" />Nouvelle
                    </Button>
                </div>

                <!-- L'historique : chacun ne voit que ses conversations. -->
                <div v-if="view === 'history'" class="min-h-0 flex-1 overflow-y-auto px-4 py-3" data-assistant-history>
                    <p v-if="chat.historyLoading.value" class="text-sm text-muted-foreground">Chargement…</p>
                    <p v-else-if="chat.history.value.length === 0" class="text-sm text-muted-foreground">Aucune conversation pour l’instant.</p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="item in chat.history.value" :key="item.id" class="flex items-center gap-2 py-2">
                            <button type="button" class="min-w-0 flex-1 rounded-md px-2 py-1.5 text-left hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-ring" @click="openConversation(item.id)">
                                <span class="block truncate text-sm text-foreground">{{ item.title || 'Conversation' }}</span>
                                <span class="block text-xs text-muted-foreground">{{ dateLabel(item.updated_at) }}</span>
                            </button>
                            <Button type="button" size="icon" variant="ghost" :aria-label="`Supprimer « ${item.title} »`" title="Supprimer" @click="chat.remove(item.id)">
                                <Trash2 class="h-4 w-4" aria-hidden="true" />
                            </Button>
                        </li>
                    </ul>
                </div>

                <template v-else>
                    <div ref="scroller" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4" aria-live="polite" data-assistant-messages>
                        <div v-if="chat.messages.value.length === 0" class="space-y-4">
                            <div class="rounded-xl border border-border bg-muted/30 p-4 text-sm text-muted-foreground">
                                <p class="font-medium text-foreground">Bonjour ! Comment utiliser RIVO ?</p>
                                <p class="mt-1">Demandez où trouver une fonction, comment faire une étape, ou pourquoi un bouton est grisé.</p>
                                <p class="mt-2 flex items-start gap-1.5 text-xs">
                                    <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    L’assistant ne donne aucun avis médical et ne voit aucune donnée de patient. Ne saisissez ni nom, ni numéro de dossier.
                                </p>
                            </div>
                            <div v-if="chat.suggestions.value.length" class="space-y-2" data-assistant-suggestions>
                                <p class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><Lightbulb class="h-3.5 w-3.5" aria-hidden="true" />Suggestions</p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="suggestion in chat.suggestions.value"
                                        :key="suggestion"
                                        type="button"
                                        class="rounded-full border border-border bg-background px-3 py-1.5 text-left text-xs text-foreground transition hover:border-primary/50 hover:bg-primary/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        @click="send(suggestion)"
                                    >{{ suggestion }}</button>
                                </div>
                            </div>
                        </div>

                        <AssistantMessage
                            v-for="(message, index) in chat.messages.value"
                            :key="index"
                            :message="message"
                            :status="index === chat.messages.value.length - 1 ? chat.status.value : ''"
                        />
                    </div>

                    <div class="space-y-2 border-t border-border px-4 py-3">
                        <p v-if="chat.notice.value" class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300" role="status">
                            <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ chat.notice.value }}
                        </p>
                        <AssistantComposer
                            ref="composer"
                            v-model="question"
                            :streaming="chat.streaming.value"
                            :max-length="maxLength"
                            @send="send()"
                            @stop="chat.stop()"
                        />
                    </div>
                </template>
            </template>
        </Sheet>
    </template>
</template>
