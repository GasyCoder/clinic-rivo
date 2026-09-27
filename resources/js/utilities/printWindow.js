/**
 * Printing outside the application layout: a blank window receives a
 * self-contained document, then opens the print dialog once its images are
 * ready. The window must be opened synchronously from the click, before any
 * await, or the browser treats it as an unwanted pop-up.
 */
export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
}[character]));

export function openPrintWindow(title) {
    const printWindow = window.open('', '_blank', 'width=1000,height=800');

    if (!printWindow) {
        return null;
    }

    printWindow.document.title = title;
    printWindow.document.body.innerHTML = '<p style="font-family:Arial,sans-serif;padding:24px">Préparation de l’impression…</p>';

    return printWindow;
}

export function writeAndPrint(printWindow, html) {
    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();

    const pending = [...printWindow.document.images].map((image) => (image.complete
        ? Promise.resolve()
        : new Promise((resolve) => { image.onload = resolve; image.onerror = resolve; })));

    Promise.all(pending).then(() => {
        printWindow.focus();
        printWindow.print();
    });
}
