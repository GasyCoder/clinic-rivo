const escapeHtml = (value) => value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const PAGE_BREAK_TAG = /<hr[^>]*class="page-break"[^>]*\/?>/;
const PAGE_SPLIT = '\u0000PAGE\u0000';

/**
 * mammoth emits the break inside the paragraph or heading that carried it
 * (`<h1><hr class="page-break" />Titre</h1>`). Splitting there would leave a
 * page starting with `</p>` or a heading without its opening tag, so the block
 * is closed before the break and reopened after it.
 */
const splitOnPageBreaks = (html) => {
    let result = html;
    const insideBlock = /<(p|h[1-6])(\s[^>]*)?>((?:(?!<\/\1>)[\s\S])*?)<hr[^>]*class="page-break"[^>]*\/?>/;
    while (insideBlock.test(result)) {
        result = result.replace(insideBlock, (_, tag, attrs = '', before) => `<${tag}${attrs}>${before}</${tag}>${PAGE_SPLIT}<${tag}${attrs}>`);
    }

    return result
        .split(new RegExp(`${PAGE_SPLIT}|${PAGE_BREAK_TAG.source}`))
        .map((page) => page.replace(/<(p|h[1-6])(\s[^>]*)?>\s*<\/\1>/g, '').trim());
};

/** A chunk with no visible text (only empty tags) is not a page. */
const hasContent = (html) => html.replace(/<[^>]+>/g, '').replace(/&nbsp;|\s/g, '') !== '';

/**
 * Word remembers where each page ended the last time it laid out the document
 * (`w:lastRenderedPageBreak`), in addition to the page breaks typed by the
 * author. mammoth ignores both. Turning every remembered boundary into an
 * explicit page break before conversion keeps Word's own pages: a 16-page
 * document imports as 16 pages instead of one.
 *
 * @param {ArrayBuffer} arrayBuffer
 * @return {Promise<ArrayBuffer>}
 */
async function markRenderedPageBreaks(arrayBuffer) {
    try {
        const JSZip = (await import('jszip')).default;
        const zip = await JSZip.loadAsync(arrayBuffer);
        const entry = zip.file('word/document.xml');

        if (!entry) return arrayBuffer;

        const xml = await entry.async('string');
        const marked = xml
            .replace(/<w:lastRenderedPageBreak\s*\/>/g, '<w:br w:type="page"/>')
            // "Saut de page avant" set on a paragraph (often through its style).
            .replace(
                /(<w:p\b[^>]*>)(<w:pPr>(?:(?!<\/w:pPr>)[\s\S])*?<w:pageBreakBefore(?:\s+w:val="(?:1|true|on)")?\s*\/>(?:(?!<\/w:pPr>)[\s\S])*?<\/w:pPr>)/g,
                '$1$2<w:r><w:br w:type="page"/></w:r>',
            );

        if (marked === xml) return arrayBuffer;

        zip.file('word/document.xml', marked);

        return await zip.generateAsync({ type: 'arraybuffer' });
    } catch {
        // An unreadable package is still handed to mammoth as it is.
        return arrayBuffer;
    }
}

/**
 * Converts a .docx file into canevas pages (ADR-087, amended): one page per
 * Word page, preserving bold/italic/underline/headings/lists/tables as far as
 * mammoth's client-side parser allows. Underline is kept via an explicit
 * styleMap — mammoth drops it by default as "non-semantic" formatting.
 *
 * mammoth/pdfjs-dist/jszip are dynamically imported so their weight is only
 * downloaded by a Super Admin who actually uses the import button.
 *
 * @param {File} file
 * @return {Promise<{pages: string[], warnings: string[], paginated: boolean}>}
 */
export async function convertDocxToPages(file) {
    const mammoth = (await import('mammoth/mammoth.browser.min.js')).default;
    const arrayBuffer = await markRenderedPageBreaks(await file.arrayBuffer());
    const { value, messages } = await mammoth.convertToHtml(
        { arrayBuffer },
        { styleMap: ['u => u', "br[type='page'] => hr.page-break"] },
    );

    const pages = splitOnPageBreaks(value).filter(hasContent);

    return {
        pages: pages.length ? pages : ['<p></p>'],
        warnings: messages.map((message) => message.message),
        paginated: pages.length > 1,
    };
}

/**
 * Extracts the text of a .pdf file, one canevas page per PDF page. A PDF's
 * original layout is never reliably reconstructible, so only line breaks are
 * kept (confirmed scope, ADR-087); the Super Admin reformats after import.
 *
 * @param {File} file
 * @return {Promise<string[]>}
 */
export async function extractPdfPages(file) {
    const [pdfjsLib, { default: pdfWorkerUrl }] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]);
    pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

    const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
    const pages = [];

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
        const page = await pdf.getPage(pageNumber);
        const { items } = await page.getTextContent();
        const lines = [];
        let line = '';

        for (const item of items) {
            line += item.str;
            if (item.hasEOL) {
                lines.push(line);
                line = '';
            }
        }
        if (line) lines.push(line);

        const paragraphs = lines.map((text) => text.trim()).filter(Boolean).map((text) => `<p>${escapeHtml(text)}</p>`);
        pages.push(paragraphs.length ? paragraphs.join('') : '<p></p>');
    }

    return pages.length ? pages : ['<p></p>'];
}
