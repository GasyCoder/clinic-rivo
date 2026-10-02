/**
 * ADR-242 — l'état d'un rapprochement par l'IA, lot par lot. Pur : l'écran
 * l'affiche, `SupplierAiMatchingPanel` le fait avancer.
 */

export const AI_BATCH_STATES = Object.freeze({
    WAITING: 'WAITING',
    RUNNING: 'RUNNING',
    DONE: 'DONE',
    FAILED: 'FAILED',
});

/** Un état neuf, à partir du plan rendu par le portail. */
export function startRun(plan) {
    return {
        run: plan.run,
        candidates: plan.candidates ?? 0,
        remaining: plan.remaining ?? 0,
        alone: plan.alone ?? 0,
        stopped: false,
        batches: (plan.batches ?? []).map((batch) => ({
            index: batch.index,
            lines: batch.lines,
            preview: batch.preview ?? [],
            state: AI_BATCH_STATES.WAITING,
            proposed: 0,
            message: '',
        })),
    };
}

/** Ce que l'écran résume : avancement, propositions, échecs. */
export function runSummary(run) {
    const batches = run?.batches ?? [];
    const count = (state) => batches.filter((batch) => batch.state === state).length;
    const done = count(AI_BATCH_STATES.DONE);
    const failed = count(AI_BATCH_STATES.FAILED);
    const running = count(AI_BATCH_STATES.RUNNING);
    const finished = done + failed;

    return {
        total: batches.length,
        done,
        failed,
        running,
        waiting: count(AI_BATCH_STATES.WAITING),
        proposed: batches.reduce((sum, batch) => sum + (batch.proposed || 0), 0),
        percent: batches.length ? Math.round((finished / batches.length) * 100) : 0,
        over: batches.length > 0 && running === 0 && (finished === batches.length || run.stopped),
    };
}

/** Le prochain lot à envoyer, ou null. */
export function nextBatch(run) {
    if (!run || run.stopped) return null;

    return run.batches.find((batch) => batch.state === AI_BATCH_STATES.WAITING) ?? null;
}
