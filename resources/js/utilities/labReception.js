/**
 * ADR-214 — les règles d'écran de la réception au laboratoire : préparer une
 * ligne de prélèvement, retrouver son tube, lire le contrôle du règlement.
 * Le serveur reste juge de chaque ligne et du règlement.
 */

export const emptySampleLine = (options = {}) => ({
    sample_type_uuid: options?.sample_types?.length === 1 ? options.sample_types[0].uuid : '',
    tube_type_uuid: '',
    quantity: 1,
});

/** Le tube qui sera posé : celui choisi, sinon celui du type de prélèvement. */
export const sampleTubeOf = (line, options = {}) => {
    const tubes = options?.tubes ?? [];
    if (line?.tube_type_uuid) return tubes.find((tube) => tube.uuid === line.tube_type_uuid) ?? null;
    const type = (options?.sample_types ?? []).find((candidate) => candidate.uuid === line?.sample_type_uuid);

    return type?.tube_uuid ? (tubes.find((tube) => tube.uuid === type.tube_uuid) ?? null) : null;
};

/** Ce qui part au serveur : les lignes complètes, sans le tube s'il est celui du type. */
export const sampleLinesPayload = (lines = []) => lines
    .filter((line) => line.sample_type_uuid)
    .map((line) => ({
        sample_type_uuid: line.sample_type_uuid,
        tube_type_uuid: line.tube_type_uuid || null,
        quantity: Math.min(10, Math.max(1, Number(line.quantity) || 1)),
    }));

/** Combien de tubes seront étiquetés. */
export const sampleLinesTubeCount = (lines = []) => sampleLinesPayload(lines).reduce((sum, line) => sum + line.quantity, 0);

export const PAYMENT_TONES = {
    SETTLED: 'success',
    NOTHING_DUE: 'success',
    DUE: 'warning',
    TO_INVOICE: 'warning',
    NOT_BILLED: 'info',
    CANCELLED: 'neutral',
};

/** La pastille d'une demande dans la file « À réceptionner ». */
export const paymentBadge = (payment) => {
    if (!payment) return null;
    if (payment.exemption === 'EMERGENCY') return { label: 'Urgence', tone: 'danger', hint: payment.exemption_label };
    if (payment.exemption === 'HOSPITALIZED') return { label: 'Hospitalisé', tone: 'info', hint: payment.exemption_label };
    if (payment.cleared) {
        return payment.unbilled_count
            ? { label: 'Prête · non facturée', tone: 'info', hint: 'Une analyse n’est pas facturée : la Réception doit régulariser.' }
            : { label: 'Prête à prélever', tone: 'success', hint: 'Rien à régler.' };
    }

    return { label: 'Règlement attendu', tone: 'warning', hint: `${payment.due_count} analyse(s) à régler à la Caisse.` };
};
