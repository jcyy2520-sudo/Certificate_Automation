const MAX_CERTIFICATE_ARTWORK_BYTES = 8 * 1024 * 1024;
const CERTIFICATE_PDF_TARGET_WIDTH = 3508;
const CERTIFICATE_PDF_MAX_SCALE = 4;

export function isPdfFile(file) {
    return Boolean(file) && (
        file.type === 'application/pdf'
        || file.name?.toLowerCase().trim().endsWith('.pdf')
    );
}

export function convertedCertificateFilename(filename) {
    const trimmed = String(filename || 'certificate').trim();
    const stem = trimmed.replace(/\.pdf$/i, '') || 'certificate';

    return `${stem}.png`;
}

export function certificatePdfRenderScale(sourceWidth, sourceHeight = null) {
    if (!Number.isFinite(sourceWidth) || sourceWidth <= 0) {
        return 1;
    }

    const preferred = Math.min(CERTIFICATE_PDF_MAX_SCALE, Math.max(1, CERTIFICATE_PDF_TARGET_WIDTH / sourceWidth));
    if (!Number.isFinite(sourceHeight) || sourceHeight <= 0) return preferred;
    // Match the server's raster budget, leaving room for canvas rounding.
    return Math.min(preferred, 5999 / Math.max(sourceWidth, sourceHeight), Math.sqrt(11988000 / (sourceWidth * sourceHeight)));
}

export async function convertCertificatePdf(file, dependencies) {
    if (!isPdfFile(file)) {
        throw new Error('Choose a PDF file to convert.');
    }

    if (file.size > MAX_CERTIFICATE_ARTWORK_BYTES) {
        throw new Error('The PDF must not be larger than 8 MB.');
    }

    const {
        getDocument,
        createCanvas = () => document.createElement('canvas'),
        createFile = (parts, name, options) => new File(parts, name, options),
    } = dependencies;
    const loadingTask = getDocument({
        data: new Uint8Array(await file.arrayBuffer()),
        isEvalSupported: false,
    });
    let pdf;

    try {
        pdf = await loadingTask.promise;

        if (pdf.numPages !== 1) {
            throw new Error('The certificate PDF must contain exactly one page.');
        }

        const page = await pdf.getPage(1);
        const baseViewport = page.getViewport({ scale: 1 });
        if (![baseViewport.width, baseViewport.height].every(value => Number.isFinite(value) && value > 0)) {
            throw new Error('The certificate PDF has invalid page dimensions.');
        }
        const viewport = page.getViewport({ scale: certificatePdfRenderScale(baseViewport.width, baseViewport.height) });
        const canvas = createCanvas();
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
            throw new Error('This browser cannot prepare the certificate PDF.');
        }

        await page.render({ canvasContext: context, viewport }).promise;
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(
                (result) => result ? resolve(result) : reject(new Error('The PDF could not be converted to an image.')),
                'image/png',
            );
        });

        if (blob.size > MAX_CERTIFICATE_ARTWORK_BYTES) {
            throw new Error('The converted artwork is larger than 8 MB. Export a smaller PDF or use PNG/JPG.');
        }

        return createFile(
            [blob],
            convertedCertificateFilename(file.name),
            { type: 'image/png', lastModified: Date.now() },
        );
    } finally {
        if (pdf) {
            await pdf.cleanup?.();
            await pdf.destroy?.();
        } else {
            await loadingTask.destroy?.();
        }
    }
}

async function pdfDependencies() {
    const [pdfjs, worker] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs'),
    ]);

    // The application deliberately forbids all Trusted Types policies. Give
    // PDF.js its worker handler directly so it performs this small one-page
    // conversion locally without attempting a blocked Worker script URL.
    globalThis.pdfjsWorker = { WorkerMessageHandler: worker.WorkerMessageHandler };

    return { getDocument: pdfjs.getDocument };
}

export function initCertificatePdfUpload(root = document) {
    const form = root.querySelector('[data-certificate-artwork-form]');
    const input = form?.querySelector('[data-file-input]');

    if (!form || !input) {
        return;
    }

    const nameElement = form.querySelector('[data-file-name]');
    const statusElement = form.querySelector('[data-file-status]');
    const submitButton = form.querySelector('[data-file-submit]');

    input.addEventListener('change', async () => {
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        if (nameElement) {
            nameElement.textContent = file.name;
        }

        if (!isPdfFile(file)) {
            if (statusElement) {
                statusElement.textContent = 'Artwork ready to upload.';
                statusElement.className = 'mt-1.5 text-[12px] text-emerald-700';
            }

            return;
        }

        input.disabled = true;
        if (submitButton) submitButton.disabled = true;
        if (statusElement) {
            statusElement.textContent = 'Preparing the PDF securely in this browser…';
            statusElement.className = 'mt-1.5 text-[12px] text-slate-600';
        }

        try {
            const converted = await convertCertificatePdf(file, await pdfDependencies());
            const transfer = new DataTransfer();
            transfer.items.add(converted);
            input.files = transfer.files;

            if (nameElement) nameElement.textContent = `${file.name} → ${converted.name}`;
            if (statusElement) {
                statusElement.textContent = 'PDF prepared as a high-resolution image. It is ready to upload.';
                statusElement.className = 'mt-1.5 text-[12px] text-emerald-700';
            }
        } catch (error) {
            input.value = '';
            if (nameElement) nameElement.textContent = 'Choose a PNG, JPG, or single-page PDF';
            if (statusElement) {
                statusElement.textContent = error instanceof Error ? error.message : 'The PDF could not be prepared.';
                statusElement.className = 'mt-1.5 text-[12px] text-red-700';
            }
        } finally {
            input.disabled = false;
            if (submitButton) submitButton.disabled = false;
        }
    });
}
