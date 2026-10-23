import { describe, expect, it, vi } from 'vitest';
import { processCertificatePlan } from '../../resources/js/certificate-send-progress.js';

describe('certificate progress processing', () => {
    it('processes an 86-person plan once each, sequentially, with accurate mixed results', async () => {
        const items = Array.from({ length: 86 }, (_, id) => ({ id }));
        let inFlight = 0;
        const send = vi.fn(async (_, index) => {
            expect(inFlight++).toBe(0);
            await Promise.resolve(); inFlight--;
            return { outcome: index === 3 ? 'failed' : index === 5 ? 'skipped' : 'accepted' };
        });
        const update = vi.fn(); const wait = vi.fn(async () => {});
        const results = await processCertificatePlan(items, { send, update, wait, interval: 2100, now: () => 0, stopped: () => false });
        expect(results).toHaveLength(86); expect(send).toHaveBeenCalledTimes(86);
        expect(results.filter(r => r.outcome === 'accepted')).toHaveLength(84);
        expect(results.filter(r => r.outcome === 'failed')).toHaveLength(1);
        expect(results.filter(r => r.outcome === 'skipped')).toHaveLength(1);
        expect(wait).toHaveBeenCalledTimes(85);
        expect(update).toHaveBeenLastCalledWith(expect.objectContaining({ index: 85, phase: 'completed' }));
    });
    it('stops on an uncertain response without replaying it or starting the next recipient', async () => {
        const send = vi.fn().mockRejectedValue(new Error('Connection lost after acceptance'));
        const results = await processCertificatePlan([1, 2, 3], { send, update: () => {}, stopped: () => false });
        expect(send).toHaveBeenCalledTimes(1); expect(results[0].outcome).toBe('unknown');
    });
    it('honors stop after the current request and keeps its result', async () => {
        let stop = false;
        const send = vi.fn(async () => { stop = true; return { outcome: 'accepted' }; });
        const results = await processCertificatePlan([1, 2], { send, update: () => {}, stopped: () => stop });
        expect(results).toHaveLength(1); expect(send).toHaveBeenCalledTimes(1);
    });
    it('halts on quota/configuration refusal without trying the rest of the group', async () => {
        const send = vi.fn(async () => ({ outcome: 'failed', halt: true }));
        expect(await processCertificatePlan([1, 2], { send, update: () => {}, stopped: () => false })).toHaveLength(1);
        expect(send).toHaveBeenCalledTimes(1);
    });
});
