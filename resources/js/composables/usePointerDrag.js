import { onBeforeUnmount, ref } from 'vue';
import { DRAG_THRESHOLD } from '@/utilities/assistantWidget';

/**
 * Un glisser à la souris, au doigt ou au stylet (Pointer Events), avec un seuil :
 * un geste plus court que `DRAG_THRESHOLD` reste un clic. Pendant le glisser, le
 * pointeur est capturé par l'élément : il ne se perd pas en sortant de la fenêtre.
 *
 *   const drag = usePointerDrag({ onStart, onMove: (dx, dy) => …, onEnd: (moved) => … });
 *   <button @pointerdown="drag.start" @click="drag.wasDragged() || open()">
 *
 * @param {{ onStart?: () => void, onMove?: (dx: number, dy: number) => void, onEnd?: (moved: boolean) => void }} handlers
 */
export function usePointerDrag({ onStart, onMove, onEnd } = {}) {
    const dragging = ref(false);
    let origin = null;
    let target = null;
    let moved = false;
    let suppressClick = false;

    const cleanup = () => {
        if (target) {
            target.removeEventListener('pointermove', move);
            target.removeEventListener('pointerup', end);
            target.removeEventListener('pointercancel', end);
        }
        target = null;
        origin = null;
    };

    function move(event) {
        if (! origin || event.pointerId !== origin.id) return;

        const dx = event.clientX - origin.x;
        const dy = event.clientY - origin.y;

        if (! moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;

        if (! moved) {
            moved = true;
            dragging.value = true;
            onStart?.();
        }

        event.preventDefault();
        onMove?.(dx, dy);
    }

    function end(event) {
        if (! origin || event.pointerId !== origin.id) return;

        try {
            target?.releasePointerCapture?.(event.pointerId);
        } catch {
            // Le pointeur a déjà été relâché.
        }

        const wasMoved = moved;
        suppressClick = wasMoved;
        moved = false;
        dragging.value = false;
        cleanup();
        onEnd?.(wasMoved);
    }

    /** À poser sur `@pointerdown`. Seul le bouton principal (ou le doigt) démarre un glisser. */
    const start = (event) => {
        if (event.button !== undefined && event.button !== 0) return;
        if (origin) cleanup();

        origin = { id: event.pointerId, x: event.clientX, y: event.clientY };
        target = event.currentTarget;
        moved = false;
        suppressClick = false;

        try {
            target?.setPointerCapture?.(event.pointerId);
        } catch {
            // Capture refusée (ancien navigateur) : le glisser marche tant que le pointeur reste dessus.
        }

        target?.addEventListener('pointermove', move);
        target?.addEventListener('pointerup', end);
        target?.addEventListener('pointercancel', end);
    };

    /** Le clic qui suit un glisser n'est pas un clic : le lire une fois l'efface. */
    const wasDragged = () => {
        const value = suppressClick;
        suppressClick = false;

        return value;
    };

    onBeforeUnmount(cleanup);

    return { start, dragging, wasDragged };
}
