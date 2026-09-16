/** A preference scoped to the signed-in account, not to a particular outage. */
export const isOfflineAlertAcknowledged = (storage, key) => {
    if (!storage) return false;

    try {
        // Non-empty values from the previous per-site implementation count as
        // acknowledged too, so the migration does not show the banner again.
        return Boolean(storage.getItem(key));
    } catch {
        return false;
    }
};

export const rememberOfflineAlert = (storage, key) => {
    if (!storage) return;

    try {
        storage.setItem(key, 'acknowledged');
    } catch {
        // Private browsing may reject storage; the Vue state still hides it.
    }
};
