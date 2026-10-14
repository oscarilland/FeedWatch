// The latency chart's data (FR-04 AC-2): merging the points each poll's
// `since` request returns, without duplicates, trimmed to 15 minutes.

export const WINDOW_SECONDS = 900;

/**
 * @param {Array<{t: number}>} existing points, oldest first
 * @param {Array<{t: number}>} incoming new points, oldest first
 * @param {number} nowSeconds
 */
export function mergeSeconds(existing, incoming, nowSeconds) {
    const byTime = new Map();
    for (const point of [...existing, ...incoming]) {
        byTime.set(point.t, point);
    }
    const oldest = nowSeconds - WINDOW_SECONDS;

    return [...byTime.values()].filter((p) => p.t >= oldest).sort((x, y) => x.t - y.t);
}

/** The newest second already held, for the next `since`. */
export function latestSecond(points) {
    return points.length === 0 ? null : points[points.length - 1].t;
}

/** One feed's chart line: [{x, y}] with gaps (null) where it delivered nothing. */
export function lineFor(points, feed) {
    return points.map((p) => ({ x: p.t, y: p[feed] ? p[feed].mean_ms : null }));
}
