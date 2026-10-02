/**
 * Une requête JSON vers le portail, avec le jeton CSRF de la page.
 * Ne jette jamais : rend `{ ok, status, data, message, errors }`, pour qu'un
 * écran dise toujours ce qui s'est passé.
 *
 * @param {string} url
 * @param {{ method?: string, body?: object }} [options]
 */
export async function jsonRequest(url, { method = 'GET', body } = {}) {
    const token = typeof document === 'undefined' ? '' : document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const json = await response.json().catch(() => null);
        const errors = json?.errors ?? {};
        const firstError = Object.values(errors)[0];

        return {
            ok: response.ok,
            status: response.status,
            data: json?.data ?? null,
            message: json?.message ?? (Array.isArray(firstError) ? firstError[0] : firstError) ?? (response.ok ? '' : 'La demande a échoué.'),
            errors,
        };
    } catch {
        return { ok: false, status: 0, data: null, message: 'Le portail est injoignable : vérifiez la connexion.', errors: {} };
    }
}

/** Copie un texte, avec un repli pour les navigateurs sans presse-papiers sécurisé. */
export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const done = document.execCommand('copy');
        area.remove();

        return done;
    }
}
