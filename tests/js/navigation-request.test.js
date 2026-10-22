import { describe, expect, it, vi } from 'vitest';
import { requestPage } from '../../resources/js/navigation-request';

describe('transient navigation responses', () => {
    it('recovers page reads from a temporary unavailable response', async () => {
        const fetchPage = vi.fn().mockResolvedValueOnce(new Response('', { status: 503 })).mockResolvedValueOnce(new Response('ok'));
        const pause = vi.fn().mockResolvedValue();
        const result = await requestPage('/admin', { method: 'GET' }, vi.fn(), fetchPage, pause);
        expect(result.status).toBe(200);
        expect(fetchPage).toHaveBeenCalledTimes(2);
        expect(pause).toHaveBeenCalledTimes(1);
    });
    it('never retries mutations, unsupported methods, authentication errors or validation errors', async () => {
        for (const [method, status] of [['POST', 503], ['PUT', 502], ['GET', 501], ['GET', 401], ['GET', 419], ['GET', 422]]) {
            const fetchPage = vi.fn().mockResolvedValue(new Response('', { status }));
            const pause = vi.fn();
            expect((await requestPage('/admin', { method }, vi.fn(), fetchPage, pause)).status).toBe(status);
            expect(fetchPage).toHaveBeenCalledTimes(1);
            expect(pause).not.toHaveBeenCalled();
        }
    });
    it('bounds retries and respects cancellation', async () => {
        const fetchPage = vi.fn().mockImplementation(() => Promise.resolve(new Response('', { status: 503 })));
        const pause = vi.fn().mockResolvedValue();
        expect((await requestPage('/admin', { method: 'GET' }, vi.fn(), fetchPage, pause)).status).toBe(503);
        expect(fetchPage).toHaveBeenCalledTimes(4);
        const controller = new AbortController(); controller.abort();
        await expect(requestPage('/admin', { signal: controller.signal }, vi.fn(), fetchPage)).rejects.toMatchObject({ name: 'AbortError' });
    });
});
