import QRCode from 'qrcode';
import { escapeHtml, openPrintWindow, writeAndPrint } from '@/utilities/printWindow';

/**
 * ADR-098 — QR labels for the medicines chosen on « Médicaments & stock ».
 * A label carries the barcode when the medicine has one, otherwise its code:
 * the counter sale search matches both, so scanning a label finds the product.
 */
export const labelContent = (medicine) => medicine.barcode || medicine.code;

export async function printMedicineLabels(medicines, siteName) {
    const printWindow = openPrintWindow('Étiquettes QR');

    if (!printWindow) {
        return false;
    }

    const labels = await Promise.all(medicines.map(async (medicine) => ({
        medicine,
        qr: await QRCode.toDataURL(labelContent(medicine), { margin: 1, width: 240 }),
    })));

    const cells = labels.map(({ medicine, qr }) => `
        <div class="label">
            <img src="${qr}" alt="">
            <div class="text">
                <strong>${escapeHtml(medicine.name)}</strong>
                <span>${escapeHtml([medicine.form_label, medicine.strength].filter(Boolean).join(' · '))}</span>
                <code>${escapeHtml(labelContent(medicine))}</code>
            </div>
        </div>`).join('');

    writeAndPrint(printWindow, `<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Étiquettes QR</title>
<style>
@page { size: A4; margin: 8mm; }
body { font-family: Arial, sans-serif; margin: 0; color: #111; }
header { font-size: 9pt; color: #555; margin-bottom: 3mm; }
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; }
.label { display: flex; gap: 3mm; align-items: center; box-sizing: border-box; height: 30mm; padding: 3mm; border: 1px dashed #bbb; page-break-inside: avoid; }
.label img { width: 24mm; height: 24mm; flex: none; }
.text { display: flex; flex-direction: column; gap: 1mm; min-width: 0; font-size: 9pt; }
.text strong { font-size: 10pt; line-height: 1.15; }
.text span { color: #555; }
.text code { font-size: 8pt; }
</style>
</head>
<body>
<header>${escapeHtml(siteName ?? '')} · ${labels.length} étiquette${labels.length > 1 ? 's' : ''}</header>
<div class="grid">${cells}</div>
</body>
</html>`);

    return true;
}
