<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft, Expand, History, Maximize2, MessageSquarePlus, Minimize2, Minus, Shrink, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import AssistantConversationView from '@/Components/Assistant/AssistantConversationView.vue';
import AssistantHistory from '@/Components/Assistant/AssistantHistory.vue';
import AssistantRobot from '@/Components/Assistant/AssistantRobot.vue';
import AssistantSetupNotice from '@/Components/Assistant/AssistantSetupNotice.vue';
import { useAssistantChat } from '@/composables/useAssistantChat';
import { useAssistantWidget } from '@/composables/useAssistantWidget';
import { usePointerDrag } from '@/composables/usePointerDrag';
import { cn } from '@/lib/cn';
import {
    BUBBLE_SIZE, bubblePosition, clampBubble, clampWindow, coversBubble, effectiveMode, isPhone, nudgeBubble,
    snapBubble, toggledSize, windowRect,
} from '@/utilities/assistantWidget';

/**
 * ADR-222 (amendement du 2026-09-29, bis) — l'assistant tient dans une bulle, comme
 * une bulle de discussion : un petit robot posé sur le bord de l'écran, qu'on déplace
 * partout d'un glisser (souris, doigt, stylet, ou les flèches du clavier). Lâché, il
 * se colle au bord le plus proche. Un clic ouvre sa fenêtre, à côté de lui :
 *
 *   petite fenêtre  pour une question rapide sans quitter son travail
 *   grande fenêtre  pour lire une réponse longue
 *   plein écran     l'historique à gauche, la conversation au centre
 *
 * La fenêtre se déplace par son en-tête ; un double-clic sur l'en-tête passe en plein
 * écran et en revient ; Échap quitte le plein écran puis réduit en bulle. La
 * conversation continue quand on change de page, et une réponse arrivée pendant que
 * la fenêtre est réduite allume un point sur la bulle.
 *
 * La bulle n'apparaît que si l'assistant est prêt pour ce compte, ou pour le Super
 * Administrateur qui peut le régler (la fenêtre lui dit alors quoi faire).
 */
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const available = computed(() => page.props.assistant?.available === true);
const setup = computed(() => (available.value ? null : page.props.assistant?.setup ?? null));
const shown = computed(() => available.value || setup.value !== null);
const maxLength = computed(() => Number(page.props.assistant?.max_length ?? 1000));
// Le nom affiché (« GasyCoder AI », réglé par RIVO_AI_BRAND) : le robot reste, le titre suit.
const assistantName = computed(() => page.props.assistant?.name || 'GasyCoder AI');
const roleLabel = computed(() => user.value?.professional_profile?.name ?? user.value?.role?.name ?? null);
const siteLabel = computed(() => page.props.site?.name ?? null);

const chat = useAssistantChat(user.value?.id ?? null);
const widget = useAssistantWidget(user.value?.id ?? null);

// L'état vient du poste (place, taille) : rien n'est rendu au serveur, sinon la page
// hydratée ne correspondrait pas à ce que le navigateur montre.
const mounted = ref(false);
const viewport = ref({ width: 1280, height: 800 });
const bubbleButton = ref(null);
const conversation = ref(null);
const pendingDelete = ref(null);
const deleting = ref(false);

const phone = computed(() => isPhone(viewport.value));
const shownMode = computed(() => effectiveMode(widget.mode.value, viewport.value));
const side = computed(() => widget.anchor.value.side);
const inlineHistory = computed(() => shownMode.value === 'full' && viewport.value.width >= 1024);
const isEmpty = computed(() => chat.messages.value.length === 0);

/* ---------------------------- la bulle ---------------------------- */

const dragPoint = ref(null);
let bubbleOrigin = null;
let reopenAfterDrag = false;

const bubbleDrag = usePointerDrag({
    onStart: () => {
        bubbleOrigin = bubblePosition(widget.anchor.value, viewport.value);
        // La fenêtre s'efface pendant le glisser et se rouvre à côté de la bulle, à sa nouvelle place.
        reopenAfterDrag = widget.open.value;
        if (reopenAfterDrag) widget.open.value = false;
    },
    onMove: (dx, dy) => {
        dragPoint.value = clampBubble({ x: bubbleOrigin.x + dx, y: bubbleOrigin.y + dy }, viewport.value);
    },
    onEnd: (moved) => {
        if (moved && dragPoint.value) widget.setAnchor(snapBubble(dragPoint.value, viewport.value));
        dragPoint.value = null;
        if (reopenAfterDrag) widget.open.value = true;
        reopenAfterDrag = false;
    },
});

const bubbleAt = computed(() => dragPoint.value ?? bubblePosition(widget.anchor.value, viewport.value));

/* --------------------------- la fenêtre --------------------------- */

const windowDragPoint = ref(null);
let windowOrigin = null;

const rect = computed(() => windowRect({
    mode: widget.mode.value,
    anchor: widget.anchor.value,
    moved: windowDragPoint.value ?? widget.moved.value,
    viewport: viewport.value,
}));

const windowDrag = usePointerDrag({
    onStart: () => {
        windowOrigin = { x: rect.value.x, y: rect.value.y };
    },
    onMove: (dx, dy) => {
        windowDragPoint.value = clampWindow({ x: windowOrigin.x + dx, y: windowOrigin.y + dy }, rect.value, viewport.value);
    },
    onEnd: (moved) => {
        if (moved && windowDragPoint.value) widget.setMoved(windowDragPoint.value);
        windowDragPoint.value = null;
    },
});

const windowMovable = computed(() => shownMode.value !== 'full');

// La bulle reste à côté de la fenêtre ; elle s'efface seulement si la fenêtre la recouvre.
const bubbleVisible = computed(() => bubbleDrag.dragging.value
    || ! widget.open.value
    || ! coversBubble(rect.value, widget.anchor.value, viewport.value));

const windowStyle = computed(() => (shownMode.value === 'full'
    ? {}
    : { left: `${rect.value.x}px`, top: `${rect.value.y}px`, width: `${rect.value.width}px`, height: `${rect.value.height}px` }));

const subtitle = computed(() => {
    if (! available.value) return 'Pas encore prêt';
    if (chat.streaming.value) return chat.status.value || 'Écrit…';

    return 'Aide à l’utilisation de RIVO';
});

/* ----------------------------- gestes ----------------------------- */

const pageContext = () => ({ url: page.url, component: page.component, hash: window.location.hash });

const loadSuggestions = () => {
    if (! widget.open.value || ! available.value || ! isEmpty.value) return;

    const path = String(page.url ?? '').split(/[?#]/)[0];
    if (widget.suggestionsPath.value === path && chat.groups.value.length) return;

    widget.suggestionsPath.value = path;
    chat.loadSuggestions(path);
};

const focusComposer = () => nextTick(() => conversation.value?.focus());

const openWindow = () => {
    widget.show();
    if (available.value && ! widget.historyLoaded.value) {
        widget.historyLoaded.value = true;
        chat.loadHistory();
    }
    loadSuggestions();
    focusComposer();
    nextTick(() => conversation.value?.scrollToEnd());
};

const minimize = () => {
    widget.hide();
    nextTick(() => bubbleButton.value?.focus());
};

const onBubbleClick = () => {
    if (bubbleDrag.wasDragged()) return;
    if (widget.open.value) minimize();
    else openWindow();
};

const onBubbleKeydown = (event) => {
    const next = nudgeBubble(widget.anchor.value, event.key, viewport.value, event.shiftKey);
    if (! next) return;

    event.preventDefault();
    widget.setAnchor(next);
};

const startWindowDrag = (event) => {
    if (! windowMovable.value || event.target.closest('button, a, input, textarea, select')) return;
    windowDrag.start(event);
};

const onHeaderDoubleClick = (event) => {
    if (phone.value || event.target.closest('button, a')) return;
    widget.toggleFull();
};

const onWindowKeydown = (event) => {
    if (event.key !== 'Escape' || event.defaultPrevented) return;

    event.preventDefault();
    if (shownMode.value === 'full' && ! phone.value) widget.toggleFull();
    else minimize();
};

const ask = (text) => {
    const value = String(text ?? '').trim();
    if (value === '' || chat.streaming.value || ! available.value) return;

    widget.draft.value = '';
    widget.view.value = 'chat';
    chat.send(value, pageContext());
    nextTick(() => conversation.value?.scrollToEnd());
};

const newConversation = () => {
    chat.reset();
    widget.view.value = 'chat';
    widget.suggestionsPath.value = null;
    loadSuggestions();
    focusComposer();
};

const showHistory = () => {
    widget.view.value = 'history';
    chat.loadHistory();
};

const openConversation = async (id) => {
    widget.view.value = 'chat';
    await chat.open(id);
    nextTick(() => conversation.value?.scrollToEnd());
};

const confirmDelete = async () => {
    if (! pendingDelete.value) return;

    deleting.value = true;
    await chat.remove(pendingDelete.value.id);
    deleting.value = false;
    pendingDelete.value = null;
    if (isEmpty.value) loadSuggestions();
};

/* ------------------------- suivi de l'écran ------------------------ */

let frame = null;
const measure = () => {
    frame = null;
    viewport.value = { width: window.innerWidth, height: window.innerHeight };
};
const onResize = () => {
    if (frame === null) frame = window.requestAnimationFrame(measure);
};

onMounted(() => {
    measure();
    window.addEventListener('resize', onResize);
    mounted.value = true;
    if (widget.open.value) {
        loadSuggestions();
        nextTick(() => conversation.value?.scrollToEnd());
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    if (frame !== null) window.cancelAnimationFrame(frame);
});

// Une autre page : les questions proposées suivent l'écran où l'on se trouve.
watch(() => page.url, loadSuggestions);

// Une réponse terminée : l'historique reprend la conversation ; fenêtre réduite, la bulle le signale.
watch(() => chat.streaming.value, (now, before) => {
    if (! before || now) return;
    chat.loadHistory();
    if (! widget.open.value) widget.unread.value = true;
});

// En plein écran large, l'historique est déjà à gauche : la vue « historique » n'a plus lieu d'être.
watch(inlineHistory, (inline) => {
    if (inline && widget.view.value === 'history') widget.view.value = 'chat';
    if (inline) chat.loadHistory();
});

watch(() => widget.view.value, (view) => {
    if (view === 'chat') focusComposer();
});
</script>

<template>
    <Teleport v-if="mounted && shown" to="body">
        <div class="print:hidden" data-assistant-widget>
            <!-- La fenêtre -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95 translate-y-2"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="opacity-0 scale-95 translate-y-2"
            >
                <section
                    v-if="widget.open.value"
                    role="dialog"
                    aria-modal="false"
                    aria-labelledby="assistant-window-title"
                    :data-mode="shownMode"
                    :style="windowStyle"
                    :class="cn(
                        'fixed z-[1200] flex flex-col overflow-hidden border-border bg-card text-card-foreground',
                        shownMode === 'full' ? 'inset-0' : 'rounded-2xl border shadow-2xl ring-1 ring-black/5',
                        side === 'right' ? 'origin-bottom-right' : 'origin-bottom-left',
                    )"
                    data-assistant-window
                    @keydown="onWindowKeydown"
                >
                    <header
                        :class="cn(
                            'flex shrink-0 items-center gap-2 border-b border-border bg-gradient-to-r from-primary/10 via-primary/5 to-transparent px-3 py-2',
                            windowMovable && 'cursor-grab touch-none select-none active:cursor-grabbing',
                        )"
                        :title="windowMovable ? 'Glissez l’en-tête pour déplacer la fenêtre, double-cliquez pour le plein écran' : undefined"
                        @pointerdown="startWindowDrag"
                        @dblclick="onHeaderDoubleClick"
                    >
                        <Button
                            v-if="available && widget.view.value === 'history' && ! inlineHistory"
                            variant="ghost"
                            size="icon"
                            aria-label="Retour à la conversation"
                            title="Retour à la conversation"
                            @click="widget.view.value = 'chat'"
                        >
                            <ArrowLeft class="h-4 w-4" aria-hidden="true" />
                        </Button>

                        <span class="relative h-9 w-9 shrink-0" aria-hidden="true">
                            <AssistantRobot :talking="chat.streaming.value" />
                            <span :class="cn('absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full ring-2 ring-card', available ? 'bg-emerald-500' : 'bg-amber-400')" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <h2 id="assistant-window-title" class="truncate text-sm font-semibold text-foreground">
                                {{ available && widget.view.value === 'history' && ! inlineHistory ? 'Conversations' : assistantName }}
                            </h2>
                            <p class="truncate text-xs text-muted-foreground" aria-live="polite">{{ subtitle }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-0.5">
                            <Button
                                v-if="available && ! inlineHistory && widget.view.value === 'chat'"
                                variant="ghost"
                                size="icon"
                                aria-label="Historique des conversations"
                                title="Historique des conversations"
                                @click="showHistory"
                            >
                                <History class="h-4 w-4" aria-hidden="true" />
                            </Button>
                            <Button
                                v-if="available"
                                variant="ghost"
                                size="icon"
                                :disabled="chat.streaming.value || isEmpty"
                                aria-label="Nouvelle conversation"
                                title="Nouvelle conversation"
                                @click="newConversation"
                            >
                                <MessageSquarePlus class="h-4 w-4" aria-hidden="true" />
                            </Button>
                            <Button
                                v-if="! phone && shownMode !== 'full'"
                                variant="ghost"
                                size="icon"
                                :aria-label="shownMode === 'large' ? 'Petite fenêtre' : 'Grande fenêtre'"
                                :title="shownMode === 'large' ? 'Petite fenêtre' : 'Grande fenêtre'"
                                data-assistant-size
                                @click="widget.setMode(toggledSize(shownMode))"
                            >
                                <Minimize2 v-if="shownMode === 'large'" class="h-4 w-4" aria-hidden="true" />
                                <Maximize2 v-else class="h-4 w-4" aria-hidden="true" />
                            </Button>
                            <Button
                                v-if="! phone"
                                variant="ghost"
                                size="icon"
                                :aria-label="shownMode === 'full' ? 'Quitter le plein écran' : 'Plein écran'"
                                :title="shownMode === 'full' ? 'Quitter le plein écran (Échap)' : 'Plein écran'"
                                :aria-pressed="shownMode === 'full'"
                                data-assistant-full
                                @click="widget.toggleFull()"
                            >
                                <Shrink v-if="shownMode === 'full'" class="h-4 w-4" aria-hidden="true" />
                                <Expand v-else class="h-4 w-4" aria-hidden="true" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Réduire en bulle"
                                title="Réduire en bulle (Échap)"
                                data-assistant-minimize
                                @click="minimize"
                            >
                                <Minus class="h-4 w-4" aria-hidden="true" />
                            </Button>
                        </div>
                    </header>

                    <div class="cq flex min-h-0 flex-1">
                        <!-- Pas encore prêt : ce qui manque, et le chemin des réglages pour qui peut le régler. -->
                        <div v-if="! available" class="min-h-0 flex-1 overflow-y-auto">
                            <AssistantSetupNotice :setup="setup" />
                        </div>

                        <template v-else>
                            <!-- Plein écran large : l'historique à gauche, toujours visible. -->
                            <aside v-if="inlineHistory" class="flex w-72 shrink-0 flex-col border-e border-border bg-muted/20" aria-label="Conversations">
                                <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                                    <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                                        <History class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Conversations
                                    </h3>
                                    <Badge v-if="chat.history.value.length" variant="secondary">{{ chat.history.value.length }}</Badge>
                                </div>
                                <div class="min-h-0 flex-1 overflow-y-auto">
                                    <AssistantHistory
                                        :items="chat.history.value"
                                        :loading="chat.historyLoading.value"
                                        :active-id="chat.conversationId.value"
                                        :disabled="chat.streaming.value"
                                        @open="openConversation"
                                        @remove="pendingDelete = $event"
                                    />
                                </div>
                            </aside>

                            <!-- Petite et grande fenêtre : l'historique remplace la conversation, le temps d'en choisir une. -->
                            <div v-if="widget.view.value === 'history' && ! inlineHistory" class="min-h-0 flex-1 overflow-y-auto" data-assistant-history-view>
                                <div class="border-b border-border p-3">
                                    <Button type="button" class="w-full" :disabled="chat.streaming.value || isEmpty" @click="newConversation">
                                        <MessageSquarePlus class="h-4 w-4" aria-hidden="true" />Nouvelle conversation
                                    </Button>
                                </div>
                                <AssistantHistory
                                    :items="chat.history.value"
                                    :loading="chat.historyLoading.value"
                                    :active-id="chat.conversationId.value"
                                    :disabled="chat.streaming.value"
                                    @open="openConversation"
                                    @remove="pendingDelete = $event"
                                />
                            </div>

                            <AssistantConversationView
                                v-else
                                ref="conversation"
                                v-model="widget.draft.value"
                                :chat="chat"
                                :user="user"
                                :max-length="maxLength"
                                :spacious="shownMode !== 'compact'"
                                :role-label="roleLabel"
                                :site-label="siteLabel"
                                @ask="ask"
                            />
                        </template>
                    </div>
                </section>
            </Transition>

            <!-- La bulle : un petit robot, déplaçable partout sur l'écran. -->
            <button
                v-show="bubbleVisible"
                ref="bubbleButton"
                type="button"
                :style="{ left: `${bubbleAt.x}px`, top: `${bubbleAt.y}px`, width: `${BUBBLE_SIZE}px`, height: `${BUBBLE_SIZE}px` }"
                :class="cn(
                    'group fixed z-[1200] grid touch-none select-none place-items-center rounded-full bg-gradient-to-br from-card to-primary/15 shadow-lg ring-1 ring-border focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                    bubbleDrag.dragging.value
                        ? 'scale-110 cursor-grabbing shadow-2xl'
                        : 'cursor-grab transition-[left,top,transform,box-shadow] duration-200 ease-out hover:scale-105 hover:shadow-xl',
                    widget.open.value && 'ring-2 ring-primary/60',
                )"
                :aria-label="widget.open.value ? `Réduire ${assistantName}` : `Ouvrir ${assistantName}`"
                :aria-expanded="widget.open.value"
                aria-describedby="assistant-bubble-hint"
                :title="`${assistantName} — cliquez pour ouvrir, glissez pour déplacer`"
                data-assistant-bubble
                @pointerdown="bubbleDrag.start"
                @click="onBubbleClick"
                @keydown="onBubbleKeydown"
            >
                <span class="pointer-events-none h-11 w-11" aria-hidden="true">
                    <AssistantRobot :talking="chat.streaming.value" />
                </span>

                <!-- Une réponse attend d'être lue. -->
                <span v-if="widget.unread.value" class="absolute -right-0.5 -top-0.5 h-4 w-4 rounded-full bg-destructive ring-2 ring-card" data-assistant-unread>
                    <span class="sr-only">Nouvelle réponse de l’assistant</span>
                </span>
                <!-- Pas encore prêt (Super Administrateur). -->
                <span v-else-if="! available" class="absolute -right-0.5 -top-0.5 grid h-4 w-4 place-items-center rounded-full bg-amber-400 text-[0.6rem] font-bold text-amber-950 ring-2 ring-card" aria-hidden="true">!</span>
                <!-- Une réponse arrive pendant que la fenêtre est réduite. -->
                <span v-if="chat.streaming.value && ! widget.open.value" class="pointer-events-none absolute inset-0 animate-ping rounded-full ring-2 ring-primary/50" aria-hidden="true" />

                <!-- Le nom, au survol : du côté de l'écran, jamais hors de lui. -->
                <span
                    v-if="! widget.open.value && ! bubbleDrag.dragging.value"
                    :class="cn(
                        'pointer-events-none absolute top-1/2 -translate-y-1/2 whitespace-nowrap rounded-full bg-foreground px-3 py-1 text-xs font-medium text-background opacity-0 shadow-md transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100',
                        side === 'right' ? 'right-full mr-3' : 'left-full ml-3',
                    )"
                    aria-hidden="true"
                >{{ assistantName }}</span>
                <span id="assistant-bubble-hint" class="sr-only">Flèches haut et bas pour déplacer la bulle, gauche et droite pour changer de bord.</span>
            </button>

            <ConfirmModal
                :open="pendingDelete !== null"
                title="Supprimer cette conversation ?"
                :description="pendingDelete ? `« ${pendingDelete.title || 'Conversation'} » sera supprimée définitivement, questions et réponses comprises.` : ''"
                confirm-label="Supprimer"
                tone="danger"
                :icon="Trash2"
                :processing="deleting"
                @update:open="(value) => { if (! value) pendingDelete = null; }"
                @confirm="confirmDelete"
            />
        </div>
    </Teleport>
</template>
