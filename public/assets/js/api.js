// Fetching JSON from FeedWatch's API, with a timeout.

export const TIMEOUT_MS = 4000;

export function basePath(document) {
    return document.body.dataset.basePath || '';
}

/**
 * @returns {Promise<object>} the parsed JSON; rejects on a network error, a
 * timeout or a non-2xx response
 */
export async function getJson(url, timeoutMs = TIMEOUT_MS) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) {
            const error = new Error(`HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }

        return await response.json();
    } finally {
        clearTimeout(timer);
    }
}
