const escapeHtml = (value) => value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

/**
 * Converts a .docx file to HTML, preserving bold/italic/underline/headings/
 * lists/tables/paragraph structure as far as mammoth's client-side parser
 * allows (ADR-087). Underline is kept via an explicit styleMap — mammoth
 * drops it by default as "non-semantic" formatting.
 *
 * mammoth/pdfjs-dist are dynamically imported (here and below) so their
 * ~1MB combined weight is only ever downloaded by a Super Admin who
 * actually uses the import button, instead of shipping on every load of
 * the canevas editor.
 * @param {File} file
 * @return {Promise<{html: string, warnings: string[]}>}
 */
export async function convertDocxToHtml(file) {
    const mammoth = (await import('mammoth/mammoth.browser.min.js')).default;
    const arrayBuffer = await file.arrayBuffer();
    const { value, messages } = await mammoth.convertToHtml(
        { arrayBuffer },
        { styleMap: ['u => u'] },
    );

    return { html: value, warnings: messages.map((message) => message.message) };
}

/**
 * Extracts plain text only from a .pdf file — a PDF's original layout is
 * never reliably reconstructible, so no formatting is attempted here
 * (confirmed scope, ADR-087). The Super Admin reformats manually after
 * import.
 * @param {File} file
 * @return {Promise<string>}
 */
export async function extractPdfPlainText(file) {
    const [pdfjsLib, { default: pdfWorkerUrl }] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]);
    pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

    const arrayBuffer = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const pageParagraphs = [];

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
        const page = await pdf.getPage(pageNumber);
        const textContent = await page.getTextContent();
        const pageText = textContent.items.map((item) => item.str).join(' ');
        pageParagraphs.push(`<p>${escapeHtml(pageText)}</p>`);
    }

    return pageParagraphs.join('<p></p>');
}
