// Retry only idempotent page reads. A failed mutation may already be committed.
export async function requestPage(url, options, onRetry = () => {}, fetchPage = fetch, pause = waitForRetry) {
    const read = (options.method || 'GET').toUpperCase() === 'GET';
    for (let attempt = 0; ; attempt++) {
        const response = await fetchPage(url, options);
        if (!read || ![502, 503, 504].includes(response.status) || attempt >= 3) return response;
        const retryAfter = Number(response.headers.get('Retry-After'));
        const delay = Math.min(5000, Math.max(500 * (2 ** attempt), Number.isFinite(retryAfter) ? retryAfter * 1000 : 0));
        onRetry();
        await response.body?.cancel();
        await pause(delay, options.signal);
    }
}

function waitForRetry(delay, signal) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) { reject(new DOMException('Aborted', 'AbortError')); return; }
        const abort = () => { clearTimeout(timer); reject(new DOMException('Aborted', 'AbortError')); };
        const timer = setTimeout(() => { signal?.removeEventListener('abort', abort); resolve(); }, delay);
        signal?.addEventListener('abort', abort, { once: true });
    });
}
