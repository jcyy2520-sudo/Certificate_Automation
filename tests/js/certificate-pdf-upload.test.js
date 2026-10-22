import { describe, expect, it, vi } from 'vitest';
import {
    certificatePdfRenderScale,
    convertedCertificateFilename,
    convertCertificatePdf,
    isPdfFile,
} from '../../resources/js/certificate-pdf-upload.js';

function pdfFile(overrides = {}) {
    return {
        name: 'Digital Integrity.PDF',
        type: 'application/pdf',
        size: 1024,
        arrayBuffer: async () => new Uint8Array([37, 80, 68, 70]).buffer,
        ...overrides,
    };
}

function conversionDependencies(pageCount = 1) {
    const page = {
        getViewport: ({ scale }) => ({ width: 842 * scale, height: 595 * scale }),
        render: vi.fn(() => ({ promise: Promise.resolve() })),
    };
    const pdf = {
        numPages: pageCount,
        getPage: vi.fn(async () => page),
        cleanup: vi.fn(async () => {}),
        destroy: vi.fn(async () => {}),
    };
    const canvas = {
        width: 0,
        height: 0,
        getContext: vi.fn(() => ({})),
        toBlob: vi.fn((callback) => callback(new Blob(['png'], { type: 'image/png' }))),
    };

    return {
        page,
        pdf,
        canvas,
        dependencies: {
            getDocument: vi.fn(() => ({ promise: Promise.resolve(pdf) })),
            createCanvas: () => canvas,
            createFile: (parts, name, options) => ({ parts, name, ...options, size: parts[0].size }),
        },
    };
}

describe('certificate PDF artwork', () => {
    it('recognizes PDFs and creates a stable PNG filename', () => {
        expect(isPdfFile(pdfFile())).toBe(true);
        expect(isPdfFile(pdfFile({ type: '', name: 'certificate.pdf' }))).toBe(true);
        expect(isPdfFile(pdfFile({ type: 'image/png', name: 'certificate.png' }))).toBe(false);
        expect(convertedCertificateFilename(' Certificate.PDF ')).toBe('Certificate.png');
    });

    it('renders near A4 print resolution without exceeding the scale cap', () => {
        expect(certificatePdfRenderScale(842)).toBe(4);
        expect(certificatePdfRenderScale(100)).toBe(4);
        expect(certificatePdfRenderScale(5000)).toBe(1);
    });

    it('converts one page while preserving its aspect ratio', async () => {
        const fixture = conversionDependencies();
        const result = await convertCertificatePdf(pdfFile(), fixture.dependencies);

        expect(result.name).toBe('Digital Integrity.png');
        expect(result.type).toBe('image/png');
        expect(fixture.canvas.width).toBe(842 * 4);
        expect(fixture.canvas.height).toBe(595 * 4);
        expect(fixture.page.render).toHaveBeenCalledOnce();
        expect(fixture.pdf.destroy).toHaveBeenCalledOnce();
    });

    it('keeps portrait and unusually large PDF pages within the server raster budget', async () => {
        for (const [width, height] of [[842, 1191], [12000, 8000], [100, 50000]]) {
            const fixture = conversionDependencies();
            fixture.page.getViewport = ({ scale }) => ({ width: width * scale, height: height * scale });
            await convertCertificatePdf(pdfFile(), fixture.dependencies);
            expect(fixture.canvas.width).toBeLessThanOrEqual(6000);
            expect(fixture.canvas.height).toBeLessThanOrEqual(6000);
            expect(fixture.canvas.width * fixture.canvas.height).toBeLessThanOrEqual(12000000);
        }
    });

    it('rejects multi-page certificate PDFs instead of silently dropping pages', async () => {
        const fixture = conversionDependencies(2);

        await expect(convertCertificatePdf(pdfFile(), fixture.dependencies))
            .rejects.toThrow('exactly one page');
        expect(fixture.page.render).not.toHaveBeenCalled();
        expect(fixture.pdf.destroy).toHaveBeenCalledOnce();
    });
});
