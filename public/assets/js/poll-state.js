// When to poll next, and whether the data is stale (ADR-005, FR-05 AC-5).
// Pure: the dashboard keeps one of these and feeds it each poll's outcome.

export const POLL_MS = 2000;
export const BACKOFF_MS = 5000;

export function initialPollState() {
    return { stale: false, staleSince: null, lastGoodAt: null, failures: 0, nextDelayMs: 0 };
}

/**
 * @param {object} state the previous state
 * @param {boolean} ok whether the poll succeeded
 * @param {number} nowMs the time, as Date.now() gives it
 */
export function nextPollState(state, ok, nowMs) {
    if (ok) {
        return { stale: false, staleSince: null, lastGoodAt: nowMs, failures: 0, nextDelayMs: POLL_MS };
    }

    // Stale from the last good data, or from now if there never was any.
    return {
        stale: true,
        staleSince: state.staleSince ?? state.lastGoodAt ?? nowMs,
        lastGoodAt: state.lastGoodAt,
        failures: state.failures + 1,
        nextDelayMs: BACKOFF_MS,
    };
}
