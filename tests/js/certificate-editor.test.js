import { describe, expect, it } from 'vitest';
import { clamp, snap } from '../../resources/js/certificate-editor.js';

describe('clamp', () => {
    it('keeps a value inside the range', () => {
        expect(clamp(150, 0, 100)).toBe(100);
        expect(clamp(-5, 0, 100)).toBe(0);
        expect(clamp(42, 0, 100)).toBe(42);
        expect(clamp(11, 12, 160)).toBe(12);
        expect(clamp(200, 12, 160)).toBe(160);
    });
});

describe('snap', () => {
    it('snaps to the target when within tolerance and reports it', () => {
        expect(snap(50.7, 50)).toEqual({ value: 50, snapped: true });
        expect(snap(48.6, 50)).toEqual({ value: 50, snapped: true });
        expect(snap(50, 50)).toEqual({ value: 50, snapped: true });
    });

    it('leaves the value untouched outside tolerance', () => {
        expect(snap(53, 50)).toEqual({ value: 53, snapped: false });
        expect(snap(30, 50)).toEqual({ value: 30, snapped: false });
    });

    it('honours a custom tolerance', () => {
        expect(snap(45, 50, 10).snapped).toBe(true);
        expect(snap(45, 50, 2).snapped).toBe(false);
    });
});
